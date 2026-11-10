package calls

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"strings"
	"testing"
	"time"

	"github.com/pion/rtcp"
	"github.com/pion/rtp"
	"github.com/pion/webrtc/v4"
)

func TestManagerNegotiatesPionPeersAndRelaysAudioAndVideoRTP(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 12*time.Second)
	defer cancel()

	signals := make(chan Signal, 8)
	manager := NewManager(nil, func(signal Signal) { signals <- signal })
	defer manager.CloseAll()

	caller, callerAudio, callerVideo := newClientPeer(t, true)
	defer caller.Close()
	callee, calleeAudio, calleeVideo := newClientPeer(t, true)
	defer callee.Close()
	pliReceived := make(chan string, 2)
	firReceived := make(chan string, 2)
	nackReceived := make(chan uint16, 1)
	watchVideoPLI := func(userID string, pc *webrtc.PeerConnection) {
		for _, sender := range pc.GetSenders() {
			if sender.Track() == nil || sender.Track().Kind() != webrtc.RTPCodecTypeVideo {
				continue
			}
			go func() {
				for {
					packets, _, err := sender.ReadRTCP()
					if err != nil {
						return
					}
					for _, packet := range packets {
						if _, ok := packet.(*rtcp.PictureLossIndication); ok {
							select {
							case pliReceived <- userID:
							default:
							}
						}
						if _, ok := packet.(*rtcp.FullIntraRequest); ok {
							select {
							case firReceived <- userID:
							default:
							}
						}
						if userID == "callee-1" {
							if nack, ok := packet.(*rtcp.TransportLayerNack); ok {
								for _, pair := range nack.Nacks {
									for _, sequence := range pair.PacketList() {
										select {
										case nackReceived <- sequence:
										default:
										}
									}
								}
							}
						}
					}
				}
			}()
		}
	}
	watchVideoPLI("caller-1", caller)
	watchVideoPLI("callee-1", callee)
	remoteTracks := make(chan string, 4)
	watchRelayedTrack := func(userID string, pc *webrtc.PeerConnection) {
		pc.OnTrack(func(track *webrtc.TrackRemote, _ *webrtc.RTPReceiver) {
			go func() {
				// A single keyframe is insufficient for an actual call. Keep
				// reading multiple packets to catch a relay that silently stops
				// after the remote track's first frame.
				for packets := 0; packets < 12; packets++ {
					if _, _, err := track.ReadRTP(); err != nil {
						return
					}
				}
				remoteTracks <- userID + ":" + track.Kind().String()
			}()
		})
	}
	watchRelayedTrack("caller-1", caller)
	watchRelayedTrack("callee-1", callee)
	callerOffer := createOffer(t, ctx, caller)
	if err := manager.Start(ctx, "conversation-1", "caller-1", "callee-1", "call-1", true, callerOffer); err != nil {
		t.Fatalf("start Pion relay call: %v", err)
	}

	ready := map[string]bool{}
	pending := map[string][]webrtc.ICECandidateInit{}
	remoteFor := map[string]*webrtc.PeerConnection{"caller-1": caller, "callee-1": callee}
	flush := func(userID string) {
		ready[userID] = true
		for _, candidate := range pending[userID] {
			if err := remoteFor[userID].AddICECandidate(candidate); err != nil {
				t.Errorf("apply buffered %s ICE candidate: %v", userID, err)
			}
		}
		delete(pending, userID)
	}
	calleeAnswered := false
	mediaDeadline := time.Time{}
	sequence := uint16(1)
	for {
		select {
		case signal := <-signals:
			switch signal.Kind {
			case "answer":
				if err := caller.SetRemoteDescription(*signal.Description); err != nil {
					t.Fatalf("set caller Pion answer: %v", err)
				}
				flush("caller-1")
			case "offer":
				if err := callee.SetRemoteDescription(*signal.Description); err != nil {
					t.Fatalf("set callee Pion offer: %v", err)
				}
				flush("callee-1")
				answer := createAnswer(t, ctx, callee)
				if err := manager.Answer("call-1", "callee-1", answer); err != nil {
					t.Fatalf("set callee Pion answer: %v", err)
				}
				calleeAnswered = true
			case "ice":
				if signal.Candidate == nil {
					continue
				}
				if ready[signal.ToUserID] {
					if err := remoteFor[signal.ToUserID].AddICECandidate(*signal.Candidate); err != nil {
						t.Fatalf("apply %s ICE candidate: %v", signal.ToUserID, err)
					}
				} else {
					pending[signal.ToUserID] = append(pending[signal.ToUserID], *signal.Candidate)
				}
			}
		case <-time.After(20 * time.Millisecond):
			if calleeAnswered && caller.ConnectionState() == webrtc.PeerConnectionStateConnected && callee.ConnectionState() == webrtc.PeerConnectionStateConnected {
				mediaDeadline = time.Now().Add(5 * time.Second)
				goto relayMedia
			}
			if ctx.Err() != nil {
				t.Fatal("Pion peer connection timed out")
			}
		}
	}

relayMedia:
	// Force loss feedback from the caller's receiving leg. The relay must
	// forward this NACK to the callee publishing leg with the source SSRC.
	const nackSequence = uint16(173)
	manager.mu.Lock()
	callerVideoSSRC := uint32(manager.calls["call-1"].caller.videoSender.GetParameters().Encodings[0].SSRC)
	manager.mu.Unlock()
	if err := caller.WriteRTCP([]rtcp.Packet{&rtcp.TransportLayerNack{
		MediaSSRC: callerVideoSSRC,
		Nacks:     rtcp.NackPairsFromSequenceNumbers([]uint16{nackSequence}),
	}}); err != nil {
		t.Fatalf("write caller video NACK: %v", err)
	}
	for userID, track := range map[string]*webrtc.TrackLocalStaticRTP{"caller-1": callerVideo, "callee-1": calleeVideo} {
		if err := track.WriteRTP(&rtp.Packet{Header: rtp.Header{Version: 2, PayloadType: 96, SequenceNumber: sequence, Timestamp: uint32(sequence) * 3000, SSRC: 43}, Payload: []byte{0x10, 0x00, 0x00}}); err != nil {
			t.Fatalf("write initial %s video RTP packet: %v", userID, err)
		}
	}
	receivedPLI := map[string]bool{}
	for {
		select {
		case userID := <-pliReceived:
			receivedPLI[userID] = true
		default:
			goto noQueuedPLI
		}
	}

noQueuedPLI:
	refreshDeadline := time.Now().Add(time.Second)
	var refreshErr error
	for time.Now().Before(refreshDeadline) {
		refreshErr = manager.HandleSignal(ctx, "conversation-1", "caller-1", "callee-1", "call-1", json.RawMessage(`{"kind":"video.refresh"}`))
		if refreshErr == nil {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if refreshErr != nil {
		t.Fatalf("request a fresh keyframe for the caller: %v", refreshErr)
	}
	keyframeRequested := false
	firRequested := false
	keyframeDeadline := time.After(time.Second)
	for !keyframeRequested || !firRequested {
		select {
		case sourceUserID := <-pliReceived:
			receivedPLI[sourceUserID] = true
			keyframeRequested = sourceUserID == "callee-1"
		case sourceUserID := <-firReceived:
			firRequested = sourceUserID == "callee-1"
		case <-keyframeDeadline:
			t.Fatalf("video refresh did not request PLI and FIR from source: PLI=%v FIR=%v", keyframeRequested, firRequested)
		}
	}
	videoRecoveryRequested := false
	recoveryDeadline := time.After(time.Second)
	for !videoRecoveryRequested {
		select {
		case signal := <-signals:
			videoRecoveryRequested = signal.Kind == "video.recover" && signal.FromUserID == "caller-1" && signal.ToUserID == "callee-1"
		case <-recoveryDeadline:
			t.Fatal("video refresh did not ask the publishing browser to restart its video sender")
		}
	}
	received := map[string]bool{}
	receivedNACK := false
	for {
		select {
		case track := <-remoteTracks:
			received[track] = true
			if received["caller-1:audio"] && received["caller-1:video"] && received["callee-1:audio"] && received["callee-1:video"] && receivedPLI["caller-1"] && receivedPLI["callee-1"] && receivedNACK {
				return
			}
		case userID := <-pliReceived:
			receivedPLI[userID] = true
		case sequence := <-nackReceived:
			if sequence != nackSequence {
				t.Fatalf("forwarded video NACK sequence = %d, want %d", sequence, nackSequence)
			}
			receivedNACK = true
			if received["caller-1:audio"] && received["caller-1:video"] && received["callee-1:audio"] && received["callee-1:video"] && receivedPLI["caller-1"] && receivedPLI["callee-1"] && receivedNACK {
				return
			}
		case <-time.After(50 * time.Millisecond):
			sequence++
			if err := callerAudio.WriteRTP(&rtp.Packet{Header: rtp.Header{Version: 2, PayloadType: 111, SequenceNumber: sequence, Timestamp: uint32(sequence) * 960, SSRC: 52}, Payload: []byte{0xf8, 0xff, 0xfe}}); err != nil {
				t.Fatalf("write caller audio RTP retry: %v", err)
			}
			if err := callerVideo.WriteRTP(&rtp.Packet{Header: rtp.Header{Version: 2, PayloadType: 96, SequenceNumber: sequence, Timestamp: uint32(sequence) * 3000, SSRC: 53}, Payload: []byte{0x10, 0x00, 0x00}}); err != nil {
				t.Fatalf("write caller video RTP retry: %v", err)
			}
			if err := calleeAudio.WriteRTP(&rtp.Packet{Header: rtp.Header{Version: 2, PayloadType: 111, SequenceNumber: sequence, Timestamp: uint32(sequence) * 960, SSRC: 42}, Payload: []byte{0xf8, 0xff, 0xfe}}); err != nil {
				t.Fatalf("write audio RTP retry: %v", err)
			}
			if err := calleeVideo.WriteRTP(&rtp.Packet{Header: rtp.Header{Version: 2, PayloadType: 96, SequenceNumber: sequence, Timestamp: uint32(sequence) * 3000, SSRC: 43}, Payload: []byte{0x10, 0x00, 0x00}}); err != nil {
				t.Fatalf("write video RTP retry: %v", err)
			}
		case <-time.After(time.Until(mediaDeadline)):
			t.Fatalf("Pion did not relay bidirectional audio/video RTP and RTCP feedback: tracks=%v PLI=%v", received, receivedPLI)
		case <-ctx.Done():
			t.Fatal("call negotiation timed out")
		}
	}
}

func TestManagerOffersOnlyRelayCodecs(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	signals := make(chan Signal, 4)
	manager := NewManager(nil, func(signal Signal) { signals <- signal })
	defer manager.CloseAll()

	caller, _, _ := newClientPeer(t, true)
	defer caller.Close()

	if err := manager.Start(ctx, "conversation-1", "caller-1", "callee-1", "call-1", true, createOffer(t, ctx, caller)); err != nil {
		t.Fatalf("start call: %v", err)
	}

	var calleeOffer *webrtc.SessionDescription
	for calleeOffer == nil {
		select {
		case signal := <-signals:
			if signal.Kind == "offer" {
				calleeOffer = signal.Description
			}
		case <-time.After(time.Second):
			t.Fatal("callee offer was not sent")
		}
	}

	for _, codec := range []string{"VP8/90000", "opus/48000"} {
		if !strings.Contains(calleeOffer.SDP, codec) {
			t.Fatalf("callee offer is missing %s:\n%s", codec, calleeOffer.SDP)
		}
	}
	for _, codec := range []string{"H264/90000", "VP9/90000", "AV1/90000"} {
		if strings.Contains(calleeOffer.SDP, codec) {
			t.Fatalf("callee offer must not negotiate %s:\n%s", codec, calleeOffer.SDP)
		}
	}
}

func TestManagerMarksCallerRemoteDescriptionReadyForTrickledICE(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	manager := NewManager(nil, func(Signal) {})
	defer manager.CloseAll()

	caller, _, _ := newClientPeer(t, true)
	defer caller.Close()

	if err := manager.Start(ctx, "conversation-1", "caller-1", "callee-1", "call-1", true, createOffer(t, ctx, caller)); err != nil {
		t.Fatalf("start call: %v", err)
	}

	manager.mu.Lock()
	active := manager.calls["call-1"]
	manager.mu.Unlock()
	if active == nil || active.caller == nil {
		t.Fatal("call was not registered")
	}
	if !active.caller.remoteReady {
		t.Fatal("caller remote description did not mark ICE ready, so trickled candidates would be buffered forever")
	}
}

func TestManagerNotifiesOtherParticipantWhenRelayPeerFails(t *testing.T) {
	signals := make(chan Signal, 1)
	manager := NewManager(nil, func(signal Signal) { signals <- signal })
	active := &call{
		id:             "call-1",
		conversationID: "conversation-1",
		callerID:       "caller-1",
		calleeID:       "callee-1",
		done:           make(chan struct{}),
	}
	manager.calls[active.id] = active

	manager.removeAndNotify(active, "callee-1", "hangup")

	select {
	case signal := <-signals:
		if signal.Kind != "hangup" || signal.FromUserID != "callee-1" || signal.ToUserID != "caller-1" || signal.CallID != active.id {
			t.Fatalf("unexpected relay failure signal: %+v", signal)
		}
	case <-time.After(time.Second):
		t.Fatal("relay failure did not notify the caller")
	}
	if _, exists := manager.calls[active.id]; exists {
		t.Fatal("failed call remained in the manager")
	}
}

func TestRelayRTPContinuesAfterOneDestinationWriteError(t *testing.T) {
	packets := []*rtp.Packet{
		{Header: rtp.Header{Version: 2, SequenceNumber: 1}},
		{Header: rtp.Header{Version: 2, SequenceNumber: 2}},
	}
	readIndex := 0
	var written []uint16
	relayRTPPackets(func() (*rtp.Packet, error) {
		if readIndex == len(packets) {
			return nil, io.EOF
		}
		packet := packets[readIndex]
		readIndex++
		return packet, nil
	}, func(packet *rtp.Packet) error {
		written = append(written, packet.SequenceNumber)
		if len(written) == 1 {
			return errors.New("temporary destination write failure")
		}
		return nil
	})

	if len(written) != 2 || written[1] != 2 {
		t.Fatalf("relay stopped after a destination write error: sequences=%v", written)
	}
}

func TestRelayRTPRecoversFromTransientReadErrors(t *testing.T) {
	reads := 0
	var written []uint16
	relayRTPPackets(func() (*rtp.Packet, error) {
		reads++
		switch reads {
		case 1, 2:
			return nil, errors.New("temporary source read failure")
		case 3:
			return &rtp.Packet{Header: rtp.Header{Version: 2, SequenceNumber: 7}}, nil
		default:
			return nil, io.EOF
		}
	}, func(packet *rtp.Packet) error {
		written = append(written, packet.SequenceNumber)
		return nil
	})

	if len(written) != 1 || written[0] != 7 {
		t.Fatalf("relay did not recover from transient read errors: sequences=%v", written)
	}
}

func newClientPeer(t *testing.T, video bool) (*webrtc.PeerConnection, *webrtc.TrackLocalStaticRTP, *webrtc.TrackLocalStaticRTP) {
	t.Helper()
	pc, err := webrtc.NewPeerConnection(webrtc.Configuration{})
	if err != nil {
		t.Fatalf("create WebRTC client peer: %v", err)
	}
	track, err := webrtc.NewTrackLocalStaticRTP(webrtc.RTPCodecCapability{MimeType: webrtc.MimeTypeOpus}, "microphone", "client")
	if err != nil {
		t.Fatalf("create WebRTC audio track: %v", err)
	}
	if _, err := pc.AddTrack(track); err != nil {
		t.Fatalf("add WebRTC audio track: %v", err)
	}
	var videoTrack *webrtc.TrackLocalStaticRTP
	if video {
		videoTrack, err = webrtc.NewTrackLocalStaticRTP(webrtc.RTPCodecCapability{MimeType: webrtc.MimeTypeVP8}, "camera", "client")
		if err != nil {
			t.Fatalf("create WebRTC video track: %v", err)
		}
		if _, err := pc.AddTrack(videoTrack); err != nil {
			t.Fatalf("add WebRTC video track: %v", err)
		}
	}
	return pc, track, videoTrack
}

func createOffer(t *testing.T, ctx context.Context, pc *webrtc.PeerConnection) webrtc.SessionDescription {
	t.Helper()
	offer, err := pc.CreateOffer(nil)
	if err != nil {
		t.Fatalf("create WebRTC offer: %v", err)
	}
	if err := setLocalAndGather(ctx, pc, offer); err != nil {
		t.Fatalf("gather WebRTC offer: %v", err)
	}
	return *pc.LocalDescription()
}

func createAnswer(t *testing.T, ctx context.Context, pc *webrtc.PeerConnection) webrtc.SessionDescription {
	t.Helper()
	answer, err := pc.CreateAnswer(nil)
	if err != nil {
		t.Fatalf("create WebRTC answer: %v", err)
	}
	if err := setLocalAndGather(ctx, pc, answer); err != nil {
		t.Fatalf("gather WebRTC answer: %v", err)
	}
	return *pc.LocalDescription()
}

func setLocalAndGather(ctx context.Context, pc *webrtc.PeerConnection, description webrtc.SessionDescription) error {
	gathered := webrtc.GatheringCompletePromise(pc)
	if err := pc.SetLocalDescription(description); err != nil {
		return err
	}
	select {
	case <-gathered:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

func waitPeerConnected(t *testing.T, ctx context.Context, pc *webrtc.PeerConnection) {
	t.Helper()
	ticker := time.NewTicker(20 * time.Millisecond)
	defer ticker.Stop()
	for {
		if pc.ConnectionState() == webrtc.PeerConnectionStateConnected {
			return
		}
		if pc.ConnectionState() == webrtc.PeerConnectionStateFailed {
			t.Fatal("Pion peer connection failed")
		}
		select {
		case <-ticker.C:
		case <-ctx.Done():
			t.Fatal("Pion peer connection timed out")
		}
	}
}
