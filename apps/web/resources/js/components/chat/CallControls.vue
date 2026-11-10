<script setup lang="ts">
import { Mic, MicOff, Minus, Phone, PhoneOff, Plus, Video } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useAuth } from '@/composables/useAuth';
import { useRealtimeStore } from '@/stores/realtime';
import { useChatStore } from '@/stores/chat';

const props = withDefaults(
    defineProps<{
        conversationId: string;
        recipientId: string | null;
        recipientName: string;
        enabled: boolean;
        videoEnabled?: boolean;
    }>(),
    { videoEnabled: true },
);
const auth = useAuth();
const realtime = useRealtimeStore();
const chat = useChatStore();
const pc = ref<RTCPeerConnection | null>(null);
const pendingIceCandidates = ref<RTCIceCandidateInit[]>([]);
const pendingLocalIceCandidates = ref<RTCIceCandidateInit[]>([]);
const signalingReady = ref(false);
const iceCandidatesByCall = ref<Record<string, RTCIceCandidateInit[]>>({});
// Browser media objects must stay native instances; deep Vue proxies are not
// valid values for HTMLMediaElement.srcObject and can stop remote playback.
const localStream = shallowRef<MediaStream | null>(null);
const remoteStream = shallowRef<MediaStream | null>(null);
const remoteVideoAvailable = ref(false);
const remoteVideoSize = ref(2);
const callId = ref<string | null>(null);
const incoming = ref<{
    id: string;
    from: string;
    conversationId: string;
    name: string;
    description: RTCSessionDescriptionInit;
} | null>(null);
const active = ref(false);
const outgoingPending = ref(false);
const callAccepted = ref(false);
const callConversationId = ref<string | null>(null);
const callRecipientId = ref<string | null>(null);
const callSignalRequestId = ref<string | null>(null);
const error = ref('');
const localVideo = ref<HTMLVideoElement | null>(null);
const remoteVideo = ref<HTMLVideoElement | null>(null);
const remoteAudio = ref<HTMLAudioElement | null>(null);
const muted = ref(false);
const cameraOff = ref(false);
const videoCall = ref(true);
const callConnectedAt = ref<number | null>(null);
const callDurationSeconds = ref(0);
let callDurationTimer: ReturnType<typeof setInterval> | null = null;
let connectionLossTimer: ReturnType<typeof setTimeout> | null = null;
let videoStallTimer: ReturnType<typeof setInterval> | null = null;
let videoFrameCallbackId: number | null = null;
let lastVideoFrameAt = 0;
let lastVideoRefreshAt = 0;
let videoRecoveryPromise: Promise<void> | null = null;
const canCall = computed(() => props.enabled && props.recipientId !== null);
const callDuration = computed(() => {
    const hours = Math.floor(callDurationSeconds.value / 3600);
    const minutes = Math.floor((callDurationSeconds.value % 3600) / 60);
    const seconds = callDurationSeconds.value % 60;
    return hours > 0
        ? `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
        : `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
});
const callStatusText = computed(() => {
    if (callConnectedAt.value !== null) return callDuration.value;
    if (outgoingPending.value) return 'Calling…';
    return callAccepted.value ? 'Connecting…' : 'Waiting for answer…';
});
const callNameInitials = computed(() =>
    (props.recipientName || 'Contact')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join(''),
);
const remoteVideoSizeClass = computed(() => {
    if (remoteVideoSize.value === 0)
        return 'h-[min(38vh,320px)] w-[65vw] max-w-[560px]';
    if (remoteVideoSize.value === 1)
        return 'h-[min(54vh,480px)] w-[78vw] max-w-[800px]';
    return 'h-[min(68vh,640px)] w-[90vw] max-w-[960px]';
});
const remoteVideoPositionClass = computed(() =>
    remoteVideoSize.value === 2
        ? 'absolute inset-0 h-full w-full'
        : 'absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2',
);

function send(
    to: string,
    id: string,
    signal: unknown,
    conversationId = callConversationId.value ?? props.conversationId,
): string | null {
    return realtime.sendCallSignal(conversationId, to, id, signal);
}
function startCallDuration(): void {
    if (callConnectedAt.value !== null) return;
    callConnectedAt.value = Date.now();
    callDurationSeconds.value = 0;
    callDurationTimer = setInterval(() => {
        if (callConnectedAt.value !== null) {
            callDurationSeconds.value = Math.floor(
                (Date.now() - callConnectedAt.value) / 1000,
            );
        }
    }, 1000);
}
function maybeStartCallDuration(): void {
    if (callAccepted.value && pc.value?.connectionState === 'connected') {
        startCallDuration();
    }
}
function resetCallDuration(): void {
    if (callDurationTimer !== null) clearInterval(callDurationTimer);
    callDurationTimer = null;
    callConnectedAt.value = null;
    callDurationSeconds.value = 0;
}
function sendLocalIce(candidate: RTCIceCandidate): void {
    const id = callId.value;
    const recipient = callRecipientId.value;
    if (!id || !recipient) return;
    if (!signalingReady.value) {
        pendingLocalIceCandidates.value.push(candidate.toJSON());
        return;
    }
    send(recipient, id, { kind: 'ice', candidate: candidate.toJSON() });
}
function flushLocalIce(): void {
    signalingReady.value = true;
    const recipient = callRecipientId.value;
    const id = callId.value;
    if (!recipient || !id) return;
    for (const candidate of pendingLocalIceCandidates.value.splice(0)) {
        send(recipient, id, { kind: 'ice', candidate });
    }
}
function requestVideoRefresh(): void {
    const id = callId.value;
    const recipient = callRecipientId.value;
    if (!id || !recipient) return;
    send(recipient, id, { kind: 'video.refresh' });
    lastVideoRefreshAt = Date.now();
}
function configuredIceServers(): RTCIceServer[] {
    const configured = import.meta.env.VITE_WEBRTC_ICE_SERVERS;
    if (typeof configured === 'string' && configured.trim() !== '') {
        try {
            const parsed: unknown = JSON.parse(configured);
            if (Array.isArray(parsed)) return parsed as RTCIceServer[];
        } catch {
            /* Use the public STUN fallback when configuration is invalid. */
        }
    }
    return [{ urls: 'stun:stun.l.google.com:19302' }];
}
function makePeer(): RTCPeerConnection {
    const connection = new RTCPeerConnection({
        iceServers: configuredIceServers(),
    });
    pc.value = connection;
    connection.ontrack = (event) => {
        // Some browsers/relay combinations deliver a track with no associated
        // MediaStream. Bind it to a local stream in that case so the caller's
        // remote <video> still receives frames and the frame-stall monitor can
        // request recovery keyframes.
        const stream =
            event.streams[0] ?? remoteStream.value ?? new MediaStream();
        if (!stream.getTracks().includes(event.track)) {
            stream.addTrack(event.track);
        }
        remoteStream.value = stream;
        if (remoteVideo.value) remoteVideo.value.srcObject = stream;
        if (event.track.kind === 'video') {
            // Start stall recovery only after this peer has an actual remote
            // video track. Starting it at call setup sends refresh requests
            // before the relay knows the source SSRC, which it rejects as
            // "video source is not publishing".
            startVideoStallMonitor(connection);
            const showRemoteVideo = () => {
                remoteVideoAvailable.value = event.track.readyState === 'live';
                // A track that just recovered from a mute needs a fresh
                // keyframe before the browser can render new frames.
                requestVideoRefresh();
            };
            const hideRemoteVideo = () => {
                remoteVideoAvailable.value = false;
            };
            if (!event.track.muted) showRemoteVideo();
            event.track.addEventListener('unmute', showRemoteVideo);
            event.track.addEventListener('mute', hideRemoteVideo);
            event.track.addEventListener('ended', hideRemoteVideo, {
                once: true,
            });
        }
    };
    connection.onicecandidate = (event) => {
        if (event.candidate) sendLocalIce(event.candidate);
    };
    connection.onconnectionstatechange = () => {
        if (connection.connectionState === 'connected') {
            if (connectionLossTimer !== null) clearTimeout(connectionLossTimer);
            connectionLossTimer = null;
            maybeStartCallDuration();
        } else if (connection.connectionState === 'failed') {
            void end(true);
        } else if (
            connection.connectionState === 'disconnected' &&
            connectionLossTimer === null
        ) {
            // ICE can briefly disconnect and recover. Keep the call alive for
            // a short grace period, then notify the other participant if this
            // peer connection never comes back.
            connectionLossTimer = setTimeout(() => {
                connectionLossTimer = null;
                if (
                    pc.value === connection &&
                    connection.connectionState === 'disconnected'
                ) {
                    void end(true);
                }
            }, 5000);
        }
    };
    return connection;
}
function startVideoStallMonitor(connection: RTCPeerConnection): void {
    lastVideoFrameAt = Date.now();
    lastVideoRefreshAt = 0;
    if (videoStallTimer !== null) clearInterval(videoStallTimer);
    videoStallTimer = setInterval(() => {
        if (
            pc.value !== connection ||
            connection.connectionState !== 'connected' ||
            !active.value
        ) {
            lastVideoFrameAt = Date.now();
            return;
        }

        // Keep requesting keyframes even while the track is muted: a browser
        // marks a remote video track muted when frames stop arriving, and
        // waiting for an unmute event would leave a frozen receiver stuck.
        if (
            Date.now() - lastVideoFrameAt < 2500 ||
            Date.now() - lastVideoRefreshAt < 2500
        )
            return;
        requestVideoRefresh();
    }, 1000);
}
function stopVideoStallMonitor(): void {
    if (videoStallTimer !== null) clearInterval(videoStallTimer);
    videoStallTimer = null;
    if (
        videoFrameCallbackId !== null &&
        remoteVideo.value?.cancelVideoFrameCallback
    ) {
        remoteVideo.value.cancelVideoFrameCallback(videoFrameCallbackId);
    }
    videoFrameCallbackId = null;
    lastVideoFrameAt = 0;
    lastVideoRefreshAt = 0;
}
async function setRemoteDescription(
    description: RTCSessionDescriptionInit,
): Promise<void> {
    const connection = pc.value;
    if (!connection) return;
    await connection.setRemoteDescription(description);
    for (const candidate of pendingIceCandidates.value.splice(0)) {
        await connection.addIceCandidate(candidate);
    }
}
async function receiveIceCandidate(
    candidate: RTCIceCandidateInit,
): Promise<void> {
    const connection = pc.value;
    if (!connection || !connection.remoteDescription) {
        pendingIceCandidates.value.push(candidate);
        return;
    }
    await connection.addIceCandidate(candidate);
}
async function acquire(withVideo: boolean): Promise<MediaStream> {
    const stream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: withVideo,
    });
    localStream.value = stream;
    if (localVideo.value) localVideo.value.srcObject = stream;
    return stream;
}
async function start(withVideo: boolean): Promise<void> {
    if (withVideo && !props.videoEnabled) return;
    if (!canCall.value || !props.recipientId) return;
    error.value = '';
    remoteVideoAvailable.value = false;
    resetCallDuration();
    callAccepted.value = false;
    try {
        const id = crypto.randomUUID();
        callId.value = id;
        videoCall.value = withVideo;
        callConversationId.value = props.conversationId;
        callRecipientId.value = props.recipientId;
        const stream = await acquire(withVideo);
        const connection = makePeer();
        stream
            .getTracks()
            .forEach((track) => connection.addTrack(track, stream));
        const offer = await connection.createOffer();
        await connection.setLocalDescription(offer);
        active.value = true;
        outgoingPending.value = true;
        callSignalRequestId.value = send(props.recipientId, id, {
            kind: 'offer',
            media: { video: withVideo },
            description: connection.localDescription,
        });
        if (!callSignalRequestId.value)
            throw new Error(
                'Realtime connection is offline. Reconnect before placing a call.',
            );
        flushLocalIce();
    } catch (cause) {
        error.value =
            cause instanceof Error
                ? cause.message
                : 'Camera or microphone access failed.';
        await end(false);
    }
}
async function accept(): Promise<void> {
    if (!incoming.value) return;
    const request = incoming.value;
    incoming.value = null;
    try {
        remoteVideoAvailable.value = false;
        resetCallDuration();
        callAccepted.value = false;
        videoCall.value = request.description.sdp?.includes('m=video') ?? false;
        callConversationId.value = request.conversationId;
        callRecipientId.value = request.from;
        callId.value = request.id;
        const stream = await acquire(videoCall.value);
        const connection = makePeer();
        stream
            .getTracks()
            .forEach((track) => connection.addTrack(track, stream));
        await setRemoteDescription(request.description);
        const answer = await connection.createAnswer();
        await connection.setLocalDescription(answer);
        callSignalRequestId.value = send(request.from, request.id, {
            kind: 'answer',
            description: connection.localDescription,
        });
        if (!callSignalRequestId.value)
            throw new Error(
                'Realtime connection is offline. Reconnect before answering.',
            );
        flushLocalIce();
        callAccepted.value = true;
        maybeStartCallDuration();
        callId.value = request.id;
        active.value = true;
    } catch (cause) {
        error.value =
            cause instanceof Error
                ? cause.message
                : 'Unable to answer the call.';
        await end(false);
    }
}
async function end(notify = true): Promise<void> {
    if (notify && callId.value && callRecipientId.value)
        send(callRecipientId.value, callId.value, { kind: 'hangup' });
    stopVideoStallMonitor();
    if (connectionLossTimer !== null) clearTimeout(connectionLossTimer);
    connectionLossTimer = null;
    resetCallDuration();
    pc.value?.close();
    pc.value = null;
    localStream.value?.getTracks().forEach((track) => track.stop());
    localStream.value = null;
    remoteStream.value = null;
    remoteVideoAvailable.value = false;
    callId.value = null;
    callConversationId.value = null;
    callRecipientId.value = null;
    callSignalRequestId.value = null;
    pendingIceCandidates.value = [];
    pendingLocalIceCandidates.value = [];
    signalingReady.value = false;
    iceCandidatesByCall.value = {};
    active.value = false;
    outgoingPending.value = false;
    callAccepted.value = false;
    incoming.value = null;
}
function decline(): void {
    if (incoming.value)
        send(
            incoming.value.from,
            incoming.value.id,
            { kind: 'decline' },
            incoming.value.conversationId,
        );
    void end(false);
}
function toggleMute(): void {
    muted.value = !muted.value;
    localStream.value?.getAudioTracks().forEach((track) => {
        track.enabled = !muted.value;
    });
}
function toggleCamera(): void {
    cameraOff.value = !cameraOff.value;
    localStream.value?.getVideoTracks().forEach((track) => {
        track.enabled = !cameraOff.value;
    });
}
async function recoverVideoSender(connection: RTCPeerConnection): Promise<void> {
    if (videoRecoveryPromise) return videoRecoveryPromise;
    const track = localStream.value?.getVideoTracks()[0];
    const sender = connection
        .getSenders()
        .find((candidate) => candidate.track?.kind === 'video');
    if (!track || !sender || track.readyState !== 'live') return;

    const recovery = (async () => {
        try {
            // PLI/FIR cannot recover a browser encoder that stopped emitting
            // RTP. Detaching and reattaching the existing camera track restarts
            // the sender pipeline without prompting for camera permission.
            await sender.replaceTrack(null);
            await new Promise((resolve) => setTimeout(resolve, 100));
            if (
                pc.value === connection &&
                active.value &&
                track.readyState === 'live'
            ) {
                await sender.replaceTrack(track);
            }
        } catch (cause) {
            console.warn('Unable to recover the local video sender.', cause);
            if (
                pc.value === connection &&
                active.value &&
                track.readyState === 'live'
            ) {
                await sender.replaceTrack(track).catch(() => {});
            }
        }
    })();
    videoRecoveryPromise = recovery;
    try {
        await recovery;
    } finally {
        if (videoRecoveryPromise === recovery) videoRecoveryPromise = null;
    }
}

watch(
    () => realtime.lastCallSignalError,
    (failure) => {
        if (!failure || failure.requestId !== callSignalRequestId.value) return;
        error.value = failure.message;
        void end(false);
    },
);

watch(
    () => props.videoEnabled,
    (enabled) => {
        if (!enabled && active.value && videoCall.value) void end();
    },
    { immediate: true },
);

watch(
    () => realtime.callSignals.length,
    () => {
        for (const envelope of realtime.takeCallSignals()) {
            const data = envelope.data as {
                conversation_id: string;
                from_user_id: string;
                to_user_id: string;
                call_id: string;
                signal: {
                    kind?: string;
                    description?: RTCSessionDescriptionInit;
                    candidate?: RTCIceCandidateInit;
                };
            };
            // The gateway broadcasts only to this authorized conversation's live
            // subscribers. Identify the remote participant by sender so an old or
            // stale contact/member ID cannot suppress the incoming call UI.
            if (data.from_user_id === auth.user.value?.id) continue;
            const signal = data.signal;
            if (
                signal.kind === 'offer' &&
                signal.description &&
                !active.value
            ) {
                if (
                    !props.videoEnabled &&
                    signal.description.sdp?.includes('m=video')
                ) {
                    send(
                        data.from_user_id,
                        data.call_id,
                        { kind: 'decline' },
                        data.conversation_id,
                    );
                    error.value =
                        'Video calls are temporarily unavailable. Please start a voice call instead.';
                    continue;
                }
                const conversation = chat.conversations.find(
                    (item) => item.id === data.conversation_id,
                );
                callId.value = data.call_id;
                callConversationId.value = data.conversation_id;
                callRecipientId.value = data.from_user_id;
                pendingIceCandidates.value =
                    iceCandidatesByCall.value[data.call_id] ?? [];
                delete iceCandidatesByCall.value[data.call_id];
                incoming.value = {
                    id: data.call_id,
                    from: data.from_user_id,
                    conversationId: data.conversation_id,
                    name:
                        conversation?.participant?.name ??
                        conversation?.title ??
                        'A contact',
                    description: signal.description,
                };
            } else if (
                data.call_id === callId.value &&
                signal.kind === 'answer' &&
                signal.description
            ) {
                void setRemoteDescription(signal.description);
            } else if (
                data.call_id === callId.value &&
                signal.kind === 'accepted'
            ) {
                callAccepted.value = true;
                outgoingPending.value = false;
                maybeStartCallDuration();
            } else if (
                data.call_id === callId.value &&
                signal.kind === 'decline'
            ) {
                error.value = `${props.recipientName} declined the call.`;
                void end(false);
            } else if (signal.kind === 'ice' && signal.candidate) {
                if (data.call_id === callId.value)
                    void receiveIceCandidate(signal.candidate);
                else if (data.to_user_id === auth.user.value?.id) {
                    const buffered =
                        iceCandidatesByCall.value[data.call_id] ?? [];
                    if (buffered.length < 64)
                        iceCandidatesByCall.value[data.call_id] = [
                            ...buffered,
                            signal.candidate,
                        ];
                }
            } else if (
                data.call_id === callId.value &&
                signal.kind === 'hangup'
            )
                void end(false);
            else if (
                data.call_id === callId.value &&
                signal.kind === 'video.recover' &&
                pc.value
            ) {
                void recoverVideoSender(pc.value);
            }
        }
    },
);
onBeforeUnmount(() => {
    void end();
});
watch([remoteStream, remoteVideo], ([stream, element], [, previousElement]) => {
    if (
        videoFrameCallbackId !== null &&
        previousElement?.cancelVideoFrameCallback
    ) {
        previousElement.cancelVideoFrameCallback(videoFrameCallbackId);
        videoFrameCallbackId = null;
    }
    if (!stream || !element) return;
    if (element.srcObject !== stream) element.srcObject = stream;
    void element.play().catch(() => {});
    if (element.requestVideoFrameCallback) {
        const onFrame = () => {
            lastVideoFrameAt = Date.now();
            videoFrameCallbackId = element.requestVideoFrameCallback(onFrame);
        };
        videoFrameCallbackId = element.requestVideoFrameCallback(onFrame);
    }
});
watch([remoteStream, remoteAudio], ([stream, element]) => {
    if (!stream || !element) return;
    if (element.srcObject !== stream) element.srcObject = stream;
    void element.play().catch(() => {});
});
watch([localStream, localVideo], ([stream, element]) => {
    if (stream && element && element.srcObject !== stream)
        element.srcObject = stream;
});
</script>

<template>
    <div
        v-if="canCall || active || incoming || outgoingPending || error"
        class="fixed z-30"
        :class="
            active && videoCall
                ? 'inset-0 bg-slate-950 text-white'
                : active
                  ? 'inset-0 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm'
                  : 'top-[4.5rem] right-4 flex items-center gap-1 rounded-xl border bg-background/95 p-1 shadow-md'
        "
    >
        <template v-if="!active && canCall && !incoming">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Start voice call"
                :disabled="!canCall"
                @click="start(false)"
                ><Phone
            /></Button>
            <Button
                v-if="videoEnabled"
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Start video call"
                :disabled="!canCall"
                @click="start(true)"
                ><Video
            /></Button>
        </template>
        <template v-if="active && videoCall">
            <video
                v-show="remoteVideoAvailable"
                ref="remoteVideo"
                autoplay
                muted
                playsinline
                :class="[remoteVideoPositionClass, remoteVideoSizeClass]"
                class="z-0 rounded-xl bg-slate-950 object-contain transition-all duration-300"
            />
            <div
                v-if="!remoteVideoAvailable"
                class="absolute inset-0 z-0 flex flex-col items-center justify-center gap-5 bg-gradient-to-b from-slate-900 to-slate-950 text-center"
            >
                <div
                    class="flex size-32 items-center justify-center rounded-full bg-gradient-to-br from-sky-400 to-indigo-600 text-4xl font-semibold shadow-xl ring-8 ring-white/5"
                >
                    {{ callNameInitials }}
                </div>
                <div>
                    <h2 class="text-2xl font-semibold">
                        {{ recipientName || 'Contact' }}
                    </h2>
                    <p class="mt-2 text-sm text-slate-300">
                        {{ callStatusText }}
                    </p>
                </div>
            </div>
            <video
                ref="localVideo"
                autoplay
                muted
                playsinline
                class="absolute top-5 right-5 z-10 h-28 w-40 rounded-xl border border-white/30 bg-black object-cover shadow-2xl sm:top-8 sm:right-8 sm:h-36 sm:w-52"
            />
            <div
                class="absolute top-5 left-5 z-10 rounded-xl bg-black/40 px-4 py-3 backdrop-blur-md sm:top-8 sm:left-8"
            >
                <p class="font-semibold">{{ recipientName || 'Contact' }}</p>
                <p class="mt-0.5 text-sm text-slate-200">
                    {{
                        callConnectedAt !== null
                            ? `Connected · ${callDuration}`
                            : callStatusText
                    }}
                </p>
            </div>
            <div
                class="absolute bottom-5 left-1/2 z-20 flex -translate-x-1/2 items-center gap-2 rounded-2xl border border-white/10 bg-slate-900/80 p-2 shadow-2xl backdrop-blur-xl sm:bottom-8 sm:gap-3 sm:p-3"
            >
                <template v-if="remoteVideoAvailable">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="rounded-xl text-white hover:bg-white/10 hover:text-white"
                        aria-label="Make video smaller"
                        :disabled="remoteVideoSize === 0"
                        @click="
                            remoteVideoSize = Math.max(0, remoteVideoSize - 1)
                        "
                        ><Minus
                    /></Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="rounded-xl text-white hover:bg-white/10 hover:text-white"
                        aria-label="Make video larger"
                        :disabled="remoteVideoSize === 2"
                        @click="
                            remoteVideoSize = Math.min(2, remoteVideoSize + 1)
                        "
                        ><Plus
                    /></Button>
                    <span class="mx-1 h-8 w-px bg-white/15" />
                </template>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-12 rounded-full bg-white/10 text-white hover:bg-white/20 hover:text-white sm:size-14"
                    :aria-label="
                        muted ? 'Unmute microphone' : 'Mute microphone'
                    "
                    @click="toggleMute"
                    ><component :is="muted ? MicOff : Mic"
                /></Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-12 rounded-full bg-white/10 text-white hover:bg-white/20 hover:text-white sm:size-14"
                    :aria-label="
                        cameraOff ? 'Turn camera on' : 'Turn camera off'
                    "
                    @click="toggleCamera"
                    ><Video
                /></Button>
                <Button
                    type="button"
                    variant="destructive"
                    size="icon"
                    class="size-12 rounded-full bg-rose-600 hover:bg-rose-500 sm:size-14"
                    aria-label="End call"
                    @click="end()"
                    ><PhoneOff
                /></Button>
            </div>
        </template>
        <audio
            v-if="active && remoteStream"
            ref="remoteAudio"
            autoplay
            class="sr-only"
        />
        <section
            v-if="active && !videoCall"
            class="w-full max-w-md rounded-3xl border border-white/10 bg-[#111827] px-8 py-10 text-center text-white shadow-2xl"
        >
            <div
                class="mx-auto mb-6 flex size-28 items-center justify-center rounded-full bg-gradient-to-br from-sky-400 to-indigo-600 text-3xl font-semibold shadow-lg ring-8 ring-white/5"
            >
                {{ callNameInitials }}
            </div>
            <p
                class="mb-2 text-xs font-medium tracking-[0.24em] text-sky-300 uppercase"
            >
                Voice call
            </p>
            <h2 class="text-2xl font-semibold">
                {{ recipientName || 'Contact' }}
            </h2>
            <p class="mt-3 text-sm text-slate-300">{{ callStatusText }}</p>
            <p
                v-if="callConnectedAt !== null"
                class="mt-1 text-xs text-slate-400"
            >
                Connected
            </p>
            <div class="mt-10 flex items-center justify-center gap-8">
                <div class="flex flex-col items-center gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-14 rounded-full bg-white/10 text-white hover:bg-white/20 hover:text-white"
                        :aria-label="
                            muted ? 'Unmute microphone' : 'Mute microphone'
                        "
                        @click="toggleMute"
                        ><component :is="muted ? MicOff : Mic" class="size-5"
                    /></Button>
                    <span class="text-xs text-slate-300">{{
                        muted ? 'Muted' : 'Microphone'
                    }}</span>
                </div>
                <div class="flex flex-col items-center gap-2">
                    <Button
                        type="button"
                        variant="destructive"
                        size="icon"
                        class="size-14 rounded-full bg-rose-600 hover:bg-rose-500"
                        aria-label="End call"
                        @click="end()"
                        ><PhoneOff class="size-5"
                    /></Button>
                    <span class="text-xs text-slate-300">End call</span>
                </div>
            </div>
        </section>
        <div
            v-if="incoming"
            class="fixed bottom-4 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-xl border bg-background p-3 shadow-xl"
            role="alertdialog"
            aria-label="Incoming call"
        >
            <span>{{ incoming.name }} is calling you</span
            ><Button type="button" @click="accept">Answer</Button
            ><Button type="button" variant="outline" @click="decline"
                >Decline</Button
            >
        </div>
        <div
            v-if="outgoingPending"
            class="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-xl border bg-background p-3 shadow-xl"
        >
            Calling {{ recipientName }}…
            <Button type="button" variant="outline" @click="end()"
                >Cancel</Button
            >
        </div>
        <div
            v-if="error"
            class="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-xl border bg-background p-3 shadow-xl"
            role="status"
        >
            {{ error }}
        </div>
        <p v-if="error" class="sr-only" role="alert">{{ error }}</p>
    </div>
</template>
