package calls

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pion/rtcp"
	"github.com/pion/rtp"
	"github.com/pion/webrtc/v4"
)

const videoKeyframeRequestInterval = 3 * time.Second
const videoSenderRecoveryInterval = 10 * time.Second

var videoKeyframeRequestSequence atomic.Uint32

// The media relay forwards raw RTP packets between the two browser legs, so both
// legs must negotiate the same codec. TrackLocalStaticRTP rewrites the SSRC and
// payload type but it does not transcode; a browser that picks H.264, VP9, or
// AV1 on one leg would otherwise be relayed into a VP8 track on the other leg
// and the receiver would stall on an undecodable frame.
const (
	relayOpusPayloadType = 111
	relayVP8PayloadType  = 96
	relayRTXPayloadType  = 97
)

type Signal struct {
	ConversationID string
	CallID         string
	FromUserID     string
	ToUserID       string
	Kind           string
	Description    *webrtc.SessionDescription
	Candidate      *webrtc.ICECandidateInit
}

type SendSignal func(Signal)

type Manager struct {
	mu         sync.Mutex
	calls      map[string]*call
	iceServers []webrtc.ICEServer
	api        *webrtc.API
	send       SendSignal
	logger     *slog.Logger
}

type call struct {
	id              string
	conversationID  string
	callerID        string
	calleeID        string
	caller          *peer
	callee          *peer
	done            chan struct{}
	closeOnce       sync.Once
	refreshMu       sync.Mutex
	videoRefreshAt  map[string]time.Time
	videoRecoveryAt map[string]time.Time
}

type peer struct {
	userID      string
	pc          *webrtc.PeerConnection
	audio       *webrtc.TrackLocalStaticRTP
	video       *webrtc.TrackLocalStaticRTP
	audioSender *webrtc.RTPSender
	videoSender *webrtc.RTPSender
	videoRemote *webrtc.TrackRemote
	mu          sync.Mutex
	remoteReady bool
	pendingICE  []webrtc.ICECandidateInit
}

func NewManager(iceServers []webrtc.ICEServer, send SendSignal) *Manager {
	api, err := newRelayAPI()
	if err != nil {
		// Fall back to Pion's default codecs so a configuration problem still
		// produces a working manager; the relay tracks remain VP8/Opus.
		api = webrtc.NewAPI()
	}

	return &Manager{calls: make(map[string]*call), iceServers: iceServers, api: api, send: send, logger: slog.Default()}
}

// SetLogger installs the gateway logger so relay failures and keyframe requests
// can be traced while diagnosing a stalled call.
func (m *Manager) SetLogger(logger *slog.Logger) {
	if logger != nil {
		m.logger = logger
	}
}

// newRelayAPI builds a Pion API that only offers Opus and VP8. Both browser
// legs then negotiate the codec the relay tracks are built for, instead of a
// codec the source browser happened to prefer.
func newRelayAPI() (*webrtc.API, error) {
	mediaEngine := &webrtc.MediaEngine{}

	if err := mediaEngine.RegisterCodec(webrtc.RTPCodecParameters{
		RTPCodecCapability: webrtc.RTPCodecCapability{
			MimeType:    webrtc.MimeTypeOpus,
			ClockRate:   48000,
			Channels:    2,
			SDPFmtpLine: "minptime=10;useinbandfec=1",
		},
		PayloadType: relayOpusPayloadType,
	}, webrtc.RTPCodecTypeAudio); err != nil {
		return nil, err
	}

	videoFeedback := []webrtc.RTCPFeedback{
		{Type: "goog-remb"},
		{Type: "ccm", Parameter: "fir"},
		{Type: "nack"},
		{Type: "nack", Parameter: "pli"},
	}
	if err := mediaEngine.RegisterCodec(webrtc.RTPCodecParameters{
		RTPCodecCapability: webrtc.RTPCodecCapability{
			MimeType:     webrtc.MimeTypeVP8,
			ClockRate:    90000,
			RTCPFeedback: videoFeedback,
		},
		PayloadType: relayVP8PayloadType,
	}, webrtc.RTPCodecTypeVideo); err != nil {
		return nil, err
	}
	if err := mediaEngine.RegisterCodec(webrtc.RTPCodecParameters{
		RTPCodecCapability: webrtc.RTPCodecCapability{
			MimeType:    webrtc.MimeTypeRTX,
			ClockRate:   90000,
			SDPFmtpLine: "apt=96",
		},
		PayloadType: relayRTXPayloadType,
	}, webrtc.RTPCodecTypeVideo); err != nil {
		return nil, err
	}

	return webrtc.NewAPI(webrtc.WithMediaEngine(mediaEngine)), nil
}

// Start accepts the caller's SDP offer, returns a Pion-generated answer to the
// caller, and offers a second Pion peer connection to the callee.
func (m *Manager) Start(ctx context.Context, conversationID, callerID, calleeID, callID string, video bool, offer webrtc.SessionDescription) error {
	m.mu.Lock()
	if _, exists := m.calls[callID]; exists {
		m.mu.Unlock()
		return errors.New("call already exists")
	}
	active := &call{id: callID, conversationID: conversationID, callerID: callerID, calleeID: calleeID, done: make(chan struct{})}
	m.calls[callID] = active
	m.mu.Unlock()

	var err error
	caller, err := m.newPeer(active, callerID, video)
	if err != nil {
		m.remove(active)
		return err
	}
	callee, err := m.newPeer(active, calleeID, video)
	if err != nil {
		m.remove(active)
		return err
	}
	m.mu.Lock()
	active.caller = caller
	active.callee = callee
	m.mu.Unlock()

	if err = active.caller.setRemoteDescription(offer); err != nil {
		m.remove(active)
		return fmt.Errorf("set caller offer: %w", err)
	}
	answer, err := active.caller.pc.CreateAnswer(nil)
	if err != nil {
		m.remove(active)
		return fmt.Errorf("create caller answer: %w", err)
	}
	if err = active.caller.pc.SetLocalDescription(answer); err != nil {
		m.remove(active)
		return fmt.Errorf("set caller answer: %w", err)
	}
	m.send(Signal{ConversationID: conversationID, CallID: callID, FromUserID: calleeID, ToUserID: callerID, Kind: "answer", Description: active.caller.pc.LocalDescription()})

	calleeOffer, err := active.callee.pc.CreateOffer(nil)
	if err != nil {
		m.remove(active)
		return fmt.Errorf("create callee offer: %w", err)
	}
	if err = active.callee.pc.SetLocalDescription(calleeOffer); err != nil {
		m.remove(active)
		return fmt.Errorf("set callee offer: %w", err)
	}
	m.send(Signal{ConversationID: conversationID, CallID: callID, FromUserID: callerID, ToUserID: calleeID, Kind: "offer", Description: active.callee.pc.LocalDescription()})
	return nil
}

func (m *Manager) HandleSignal(ctx context.Context, conversationID, fromUserID, toUserID, callID string, raw json.RawMessage) error {
	var payload struct {
		Kind        string                     `json:"kind"`
		Description *webrtc.SessionDescription `json:"description"`
		Candidate   *webrtc.ICECandidateInit   `json:"candidate"`
		Media       struct {
			Video bool `json:"video"`
		} `json:"media"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return fmt.Errorf("decode WebRTC signal: %w", err)
	}
	switch payload.Kind {
	case "offer":
		if payload.Description == nil || !IsOffer(*payload.Description) {
			return errors.New("offer signal requires an SDP offer")
		}
		return m.Start(ctx, conversationID, fromUserID, toUserID, callID, payload.Media.Video, *payload.Description)
	case "answer":
		if payload.Description == nil || !IsAnswer(*payload.Description) {
			return errors.New("answer signal requires an SDP answer")
		}
		return m.Answer(callID, fromUserID, *payload.Description)
	case "ice":
		if payload.Candidate == nil {
			return errors.New("ICE signal requires a candidate")
		}
		return m.AddICECandidate(callID, fromUserID, *payload.Candidate)
	case "video.refresh":
		return m.RefreshVideo(callID, fromUserID)
	case "decline", "hangup":
		if !m.End(callID, fromUserID, payload.Kind) {
			return errors.New("call does not exist or user is not a participant")
		}
		return nil
	default:
		return errors.New("unsupported call signal")
	}
}

func (m *Manager) Answer(callID, userID string, answer webrtc.SessionDescription) error {
	active, target, ok := m.getPeer(callID, userID)
	if !ok || userID != active.calleeID {
		return errors.New("call answer is not from its recipient")
	}
	if err := target.setRemoteDescription(answer); err != nil {
		return fmt.Errorf("set callee answer: %w", err)
	}
	m.send(Signal{
		ConversationID: active.conversationID,
		CallID:         callID,
		FromUserID:     active.calleeID,
		ToUserID:       active.callerID,
		Kind:           "accepted",
	})
	return nil
}

func (m *Manager) AddICECandidate(callID, userID string, candidate webrtc.ICECandidateInit) error {
	_, target, ok := m.getPeer(callID, userID)
	if !ok {
		return errors.New("call does not exist for user")
	}
	return target.addICECandidate(candidate)
}

// RefreshVideo requests a fresh keyframe from the other participant after the
// receiving browser detects that its video frames have stopped advancing.
func (m *Manager) RefreshVideo(callID, requestingUserID string) error {
	active, _, ok := m.getPeer(callID, requestingUserID)
	if !ok {
		return errors.New("call does not exist for user")
	}

	sourceUserID := active.callerID
	if requestingUserID == active.callerID {
		sourceUserID = active.calleeID
	}
	_, source, ok := m.getPeer(callID, sourceUserID)
	if !ok {
		return errors.New("video source is not available")
	}

	source.mu.Lock()
	remote := source.videoRemote
	source.mu.Unlock()
	if remote == nil {
		return errors.New("video source is not publishing")
	}

	active.refreshMu.Lock()
	if active.videoRefreshAt == nil {
		active.videoRefreshAt = make(map[string]time.Time)
	}
	now := time.Now()
	if last := active.videoRefreshAt[requestingUserID]; now.Sub(last) < time.Second {
		active.refreshMu.Unlock()
		return nil
	}
	active.videoRefreshAt[requestingUserID] = now
	shouldRecoverSender := active.videoRecoveryAt == nil || now.Sub(active.videoRecoveryAt[requestingUserID]) >= videoSenderRecoveryInterval
	if shouldRecoverSender {
		if active.videoRecoveryAt == nil {
			active.videoRecoveryAt = make(map[string]time.Time)
		}
		active.videoRecoveryAt[requestingUserID] = now
	}
	active.refreshMu.Unlock()

	m.logger.Info("requesting call video keyframe", "call_id", callID, "requesting_user_id", requestingUserID, "source_user_id", sourceUserID, "ssrc", uint32(remote.SSRC()))
	if err := writeVideoKeyframeRequest(source.pc, uint32(remote.SSRC())); err != nil {
		return err
	}
	if shouldRecoverSender {
		m.logger.Info("requesting call video sender recovery", "call_id", callID, "requesting_user_id", requestingUserID, "source_user_id", sourceUserID)
		m.send(Signal{ConversationID: active.conversationID, CallID: callID, FromUserID: requestingUserID, ToUserID: sourceUserID, Kind: "video.recover"})
	}
	return nil
}

// writeVideoKeyframeRequest sends both PLI and FIR. Browsers generally honor
// PLI, but some encoder implementations recover more reliably from FIR after
// the remote RTP stream has gone idle. Sending both gives the source encoder
// an explicit intra-frame request without changing the negotiated media path.
func writeVideoKeyframeRequest(pc *webrtc.PeerConnection, ssrc uint32) error {
	sequence := uint8(videoKeyframeRequestSequence.Add(1))
	return pc.WriteRTCP([]rtcp.Packet{
		&rtcp.PictureLossIndication{MediaSSRC: ssrc},
		&rtcp.FullIntraRequest{
			MediaSSRC: ssrc,
			FIR:       []rtcp.FIREntry{{SSRC: ssrc, SequenceNumber: sequence}},
		},
	})
}

func (m *Manager) End(callID, userID, kind string) bool {
	m.mu.Lock()
	active := m.calls[callID]
	if active == nil || (userID != active.callerID && userID != active.calleeID) {
		m.mu.Unlock()
		return false
	}
	delete(m.calls, callID)
	m.mu.Unlock()
	active.close()
	otherID := active.callerID
	if userID == active.callerID {
		otherID = active.calleeID
	}
	m.send(Signal{ConversationID: active.conversationID, CallID: callID, FromUserID: userID, ToUserID: otherID, Kind: kind})
	return true
}

func (m *Manager) CloseAll() {
	m.mu.Lock()
	active := make([]*call, 0, len(m.calls))
	for id, current := range m.calls {
		delete(m.calls, id)
		active = append(active, current)
	}
	m.mu.Unlock()
	for _, current := range active {
		current.close()
	}
}

func (m *Manager) newPeer(active *call, userID string, video bool) (*peer, error) {
	pc, err := m.api.NewPeerConnection(webrtc.Configuration{ICEServers: m.iceServers})
	if err != nil {
		return nil, err
	}
	result := &peer{userID: userID, pc: pc}
	result.audio, err = webrtc.NewTrackLocalStaticRTP(webrtc.RTPCodecCapability{
		MimeType:  webrtc.MimeTypeOpus,
		ClockRate: 48000,
		Channels:  2,
	}, "audio", active.id)
	if err != nil {
		_ = pc.Close()
		return nil, err
	}
	result.audioSender, err = result.addSendReceiveTrack(webrtc.RTPCodecTypeAudio, result.audio)
	if err != nil {
		_ = pc.Close()
		return nil, err
	}
	if video {
		result.video, err = webrtc.NewTrackLocalStaticRTP(webrtc.RTPCodecCapability{
			MimeType:  webrtc.MimeTypeVP8,
			ClockRate: 90000,
		}, "video", active.id)
		if err != nil {
			_ = pc.Close()
			return nil, err
		}
		result.videoSender, err = result.addSendReceiveTrack(webrtc.RTPCodecTypeVideo, result.video)
		if err != nil {
			_ = pc.Close()
			return nil, err
		}
	}

	pc.OnTrack(func(remote *webrtc.TrackRemote, _ *webrtc.RTPReceiver) {
		if remote.Kind() == webrtc.RTPCodecTypeVideo {
			result.mu.Lock()
			result.videoRemote = remote
			result.mu.Unlock()
		}
		go m.relayTrack(active, userID, remote)
	})
	pc.OnICECandidate(func(candidate *webrtc.ICECandidate) {
		if candidate == nil {
			return
		}
		fromUserID, toUserID := active.calleeID, active.callerID
		if userID == active.calleeID {
			fromUserID, toUserID = active.callerID, active.calleeID
		}
		init := candidate.ToJSON()
		m.send(Signal{ConversationID: active.conversationID, CallID: active.id, FromUserID: fromUserID, ToUserID: toUserID, Kind: "ice", Candidate: &init})
	})
	pc.OnICEConnectionStateChange(func(state webrtc.ICEConnectionState) {
		if state == webrtc.ICEConnectionStateFailed || state == webrtc.ICEConnectionStateClosed {
			m.removeAndNotify(active, userID, "hangup")
		}
	})
	return result, nil
}

func (p *peer) addSendReceiveTrack(kind webrtc.RTPCodecType, track *webrtc.TrackLocalStaticRTP) (*webrtc.RTPSender, error) {
	transceiver, err := p.pc.AddTransceiverFromKind(kind, webrtc.RTPTransceiverInit{Direction: webrtc.RTPTransceiverDirectionSendrecv})
	if err != nil {
		return nil, err
	}
	return transceiver.Sender(), transceiver.Sender().ReplaceTrack(track)
}

func (m *Manager) relayTrack(active *call, fromUserID string, remote *webrtc.TrackRemote) {
	var destination *webrtc.TrackLocalStaticRTP
	var destinationPeer, sourcePeer *peer
	if fromUserID == active.callerID {
		destinationPeer, sourcePeer = active.callee, active.caller
	} else {
		destinationPeer, sourcePeer = active.caller, active.callee
	}
	if destinationPeer == nil || sourcePeer == nil {
		return
	}
	if remote.Kind() == webrtc.RTPCodecTypeAudio {
		destination = destinationPeer.audio
	} else {
		destination = destinationPeer.video
	}
	if destination == nil {
		return
	}
	m.logger.Info("relaying call track", "call_id", active.id, "from_user_id", fromUserID, "kind", remote.Kind().String(), "ssrc", uint32(remote.SSRC()), "payload_type", uint16(remote.PayloadType()), "mime_type", remote.Codec().MimeType)
	feedbackSender := destinationPeer.audioSender
	if remote.Kind() == webrtc.RTPCodecTypeVideo {
		feedbackSender = destinationPeer.videoSender
	}
	go m.relayRTCPFeedback(active, sourcePeer, remote, feedbackSender)
	if remote.Kind() == webrtc.RTPCodecTypeVideo {
		// The other browser may join after the sender already emitted its first
		// keyframe. Periodic PLI requests make the source browser emit a fresh
		// VP8 keyframe so a late receiver can start decoding and recover from loss.
		go m.requestVideoKeyframes(active, fromUserID, remote)
	}
	var readPackets, writtenPackets, readPayloadBytes, writtenPayloadBytes atomic.Uint64
	relayDone := make(chan struct{})
	if remote.Kind() == webrtc.RTPCodecTypeVideo {
		go func() {
			ticker := time.NewTicker(5 * time.Second)
			defer ticker.Stop()
			var previousRead, previousWritten uint64
			for {
				select {
				case <-ticker.C:
					currentRead := readPackets.Load()
					currentWritten := writtenPackets.Load()
					m.logger.Info("call relay RTP flow", "call_id", active.id, "from_user_id", fromUserID, "to_user_id", destinationPeer.userID, "ssrc", uint32(remote.SSRC()), "read_packets_delta", currentRead-previousRead, "written_packets_delta", currentWritten-previousWritten, "read_packets_total", currentRead, "written_packets_total", currentWritten, "read_payload_bytes", readPayloadBytes.Load(), "written_payload_bytes", writtenPayloadBytes.Load())
					previousRead, previousWritten = currentRead, currentWritten
				case <-relayDone:
					return
				case <-active.done:
					return
				}
			}
		}()
	}
	defer close(relayDone)
	var loggedReadError, loggedWriteError bool
	relayRTPPackets(
		func() (*rtp.Packet, error) {
			packet, _, err := remote.ReadRTP()
			if err == nil {
				readPackets.Add(1)
				readPayloadBytes.Add(uint64(len(packet.Payload)))
			}
			return packet, err
		},
		func(packet *rtp.Packet) error {
			if err := destination.WriteRTP(packet); err != nil {
				return err
			}
			writtenPackets.Add(1)
			writtenPayloadBytes.Add(uint64(len(packet.Payload)))
			return nil
		},
		func(operation string, err error) {
			switch operation {
			case "read":
				if loggedReadError {
					return
				}
				loggedReadError = true
				m.logger.Warn("call relay RTP read failed", "call_id", active.id, "from_user_id", fromUserID, "kind", remote.Kind().String(), "ssrc", uint32(remote.SSRC()), "error", err)
			case "write":
				if loggedWriteError {
					return
				}
				loggedWriteError = true
				m.logger.Warn("call relay RTP write failed", "call_id", active.id, "from_user_id", fromUserID, "kind", remote.Kind().String(), "ssrc", uint32(remote.SSRC()), "error", err)
			}
		},
	)
}

// relayRTPPackets keeps forwarding after a destination write fails for one
// packet. A dropped RTP packet can be recovered through NACK/keyframe feedback;
// terminating this loop would freeze the whole video track for the rest of the
// call after a single transient write error. Terminal read errors (the track or
// peer connection closed) end the loop, while a run of transient read errors is
// capped so a dead track cannot spin forever.
func relayRTPPackets(read func() (*rtp.Packet, error), write func(*rtp.Packet) error, onError ...func(operation string, err error)) {
	report := func(operation string, err error) {
		if len(onError) > 0 && onError[0] != nil {
			onError[0](operation, err)
		}
	}
	var consecutiveReadErrors int
	for {
		packet, err := read()
		if err != nil {
			if errors.Is(err, io.EOF) || errors.Is(err, io.ErrClosedPipe) {
				return
			}
			consecutiveReadErrors++
			report("read", err)
			if consecutiveReadErrors >= 10 {
				return
			}
			time.Sleep(10 * time.Millisecond)
			continue
		}
		consecutiveReadErrors = 0
		if err := write(packet); err != nil {
			report("write", err)
		}
	}
}

// relayRTCPFeedback forwards downstream PLI/FIR and NACK feedback to the
// browser that published the source track. TrackLocalStaticRTP rewrites the
// destination SSRC but preserves RTP sequence numbers, so NACK packet IDs
// remain valid when forwarded upstream.
func (m *Manager) relayRTCPFeedback(active *call, source *peer, remote *webrtc.TrackRemote, sender *webrtc.RTPSender) {
	if sender == nil {
		return
	}
	for {
		packets, _, err := sender.ReadRTCP()
		if err != nil {
			return
		}
		for _, packet := range packets {
			var feedback rtcp.Packet
			switch value := packet.(type) {
			case *rtcp.PictureLossIndication:
				copy := *value
				copy.MediaSSRC = uint32(remote.SSRC())
				feedback = &copy
			case *rtcp.FullIntraRequest:
				copy := *value
				copy.MediaSSRC = uint32(remote.SSRC())
				for index := range copy.FIR {
					copy.FIR[index].SSRC = uint32(remote.SSRC())
				}
				feedback = &copy
			case *rtcp.TransportLayerNack:
				copy := *value
				copy.MediaSSRC = uint32(remote.SSRC())
				feedback = &copy
			}
			if feedback != nil {
				_ = source.pc.WriteRTCP([]rtcp.Packet{feedback})
			}
		}
		select {
		case <-active.done:
			return
		default:
		}
	}
}

func (m *Manager) requestVideoKeyframes(active *call, sourceUserID string, remote *webrtc.TrackRemote) {
	source := active.caller
	if sourceUserID == active.calleeID {
		source = active.callee
	}
	if source == nil {
		return
	}

	ticker := time.NewTicker(videoKeyframeRequestInterval)
	defer ticker.Stop()
	request := func() {
		if err := writeVideoKeyframeRequest(source.pc, uint32(remote.SSRC())); err != nil {
			m.logger.Warn("call video keyframe request failed", "call_id", active.id, "source_user_id", sourceUserID, "ssrc", uint32(remote.SSRC()), "error", err)
		}
	}

	// Ask immediately, then a couple more times while the receiving browser is
	// still binding the relayed track. The first keyframe can be written before
	// the other leg has an active sender, and without this burst the receiver
	// would wait for the next periodic request.
	request()
	startupTimers := make([]*time.Timer, 0, 2)
	for _, delay := range []time.Duration{500 * time.Millisecond, 1500 * time.Millisecond} {
		startupTimers = append(startupTimers, time.AfterFunc(delay, request))
	}
	defer func() {
		for _, timer := range startupTimers {
			timer.Stop()
		}
	}()

	for {
		select {
		case <-ticker.C:
			request()
		case <-active.done:
			return
		}
	}
}

func (p *peer) setRemoteDescription(description webrtc.SessionDescription) error {
	if err := p.pc.SetRemoteDescription(description); err != nil {
		return err
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	p.remoteReady = true
	for _, candidate := range p.pendingICE {
		if err := p.pc.AddICECandidate(candidate); err != nil {
			return err
		}
	}
	p.pendingICE = nil
	return nil
}

func (p *peer) addICECandidate(candidate webrtc.ICECandidateInit) error {
	p.mu.Lock()
	defer p.mu.Unlock()
	if !p.remoteReady {
		p.pendingICE = append(p.pendingICE, candidate)
		return nil
	}
	return p.pc.AddICECandidate(candidate)
}

func (m *Manager) getPeer(callID, userID string) (*call, *peer, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	active := m.calls[callID]
	if active == nil {
		return nil, nil, false
	}
	if active.callerID == userID {
		return active, active.caller, active.caller != nil
	}
	if active.calleeID == userID {
		return active, active.callee, active.callee != nil
	}
	return active, nil, false
}

func (m *Manager) remove(active *call) {
	m.mu.Lock()
	if m.calls[active.id] != active {
		m.mu.Unlock()
		return
	}
	delete(m.calls, active.id)
	m.mu.Unlock()
	active.close()
}

// removeAndNotify tears down a relay that failed independently of an explicit
// hangup, then tells the other participant to close its browser peer connection
// and call UI. Explicit End removes the call before closing peers, so this
// callback does not emit a duplicate signal during normal teardown.
func (m *Manager) removeAndNotify(active *call, failedUserID, kind string) {
	m.mu.Lock()
	if m.calls[active.id] != active {
		m.mu.Unlock()
		return
	}
	delete(m.calls, active.id)
	m.mu.Unlock()

	active.close()
	otherUserID := active.callerID
	if failedUserID == active.callerID {
		otherUserID = active.calleeID
	}
	m.send(Signal{
		ConversationID: active.conversationID,
		CallID:         active.id,
		FromUserID:     failedUserID,
		ToUserID:       otherUserID,
		Kind:           kind,
	})
}

func (c *call) close() {
	c.closeOnce.Do(func() { close(c.done) })
	if c.caller != nil && c.caller.pc != nil {
		_ = c.caller.pc.Close()
	}
	if c.callee != nil && c.callee.pc != nil {
		_ = c.callee.pc.Close()
	}
}

func (s Signal) SessionDescription() (webrtc.SessionDescription, error) {
	if s.Description == nil {
		return webrtc.SessionDescription{}, errors.New("call signal has no SDP description")
	}
	return *s.Description, nil
}

func IsOffer(s webrtc.SessionDescription) bool { return s.Type == webrtc.SDPTypeOffer }

func IsAnswer(s webrtc.SessionDescription) bool { return s.Type == webrtc.SDPTypeAnswer }

func CandidateFromSignal(s Signal) (webrtc.ICECandidateInit, error) {
	if s.Candidate == nil {
		return webrtc.ICECandidateInit{}, errors.New("call signal has no ICE candidate")
	}
	return *s.Candidate, nil
}
