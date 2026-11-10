import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useRealtimeStore } from '@/stores/realtime';
import CallControls from './CallControls.vue';

const mocks = vi.hoisted(() => ({
    userId: 'receiver-1',
    callSignals: [] as unknown[],
    sendCallSignal: vi.fn(),
    subscribe: vi.fn(),
    takeCallSignals: vi.fn(),
}));

vi.mock('@/composables/useAuth', () => ({
    useAuth: () => ({ user: { value: { id: mocks.userId } } }),
}));

vi.mock('@/stores/chat', () => ({
    useChatStore: () => ({
        conversations: [
            {
                id: 'conversation-1',
                title: null,
                participant: { id: 'caller-1', name: 'Caller Name' },
            },
        ],
    }),
}));

vi.mock('@/stores/realtime', async () => {
    const { reactive } = await import('vue');
    const store = reactive({
        callSignals: mocks.callSignals,
        lastCallSignalError: null as {
            requestId: string;
            code: string;
            message: string;
        } | null,
        sendCallSignal: mocks.sendCallSignal,
        subscribe: mocks.subscribe,
        takeCallSignals() {
            const pending = [...this.callSignals];
            this.callSignals.splice(0);
            return pending;
        },
    });
    mocks.takeCallSignals.mockImplementation(() => store.takeCallSignals());
    return { useRealtimeStore: () => store };
});

class FakePeerConnection {
    public static instances: FakePeerConnection[] = [];
    public static emitCandidateOnSetLocal = false;
    public connectionState: RTCPeerConnectionState = 'new';
    public iceGatheringState: RTCIceGatheringState = 'complete';
    public localDescription: RTCSessionDescriptionInit | null = null;
    public remoteDescription: RTCSessionDescriptionInit | null = null;
    public onicecandidate: ((event: RTCPeerConnectionIceEvent) => void) | null =
        null;
    public ontrack: ((event: RTCTrackEvent) => void) | null = null;
    public onconnectionstatechange: (() => void) | null = null;
    public readonly addIceCandidate = vi.fn(async () => {});
    public readonly senders: {
        track: MediaStreamTrack;
        replaceTrack: ReturnType<typeof vi.fn>;
    }[] = [];
    public readonly addTrack = vi.fn((track: MediaStreamTrack) => {
        const sender = {
            track,
            replaceTrack: vi.fn(async (replacement: MediaStreamTrack | null) => {
                sender.track = replacement as MediaStreamTrack;
            }),
        };
        this.senders.push(sender);
        return sender;
    });
    public getSenders(): RTCRtpSender[] {
        return this.senders as unknown as RTCRtpSender[];
    }
    public addEventListener(): void {}
    public removeEventListener(): void {}

    public constructor() {
        FakePeerConnection.instances.push(this);
    }

    public async setRemoteDescription(
        description: RTCSessionDescriptionInit,
    ): Promise<void> {
        this.remoteDescription = description;
    }

    public async createAnswer(): Promise<RTCSessionDescriptionInit> {
        return { type: 'answer', sdp: 'answer-sdp' };
    }

    public async createOffer(): Promise<RTCSessionDescriptionInit> {
        return { type: 'offer', sdp: 'offer-sdp' };
    }

    public async setLocalDescription(
        description: RTCSessionDescriptionInit,
    ): Promise<void> {
        this.localDescription = description;
        if (FakePeerConnection.emitCandidateOnSetLocal) {
            this.onicecandidate?.({
                candidate: { toJSON: () => ({ candidate: 'local-candidate' }) },
            } as unknown as RTCPeerConnectionIceEvent);
        }
    }

    public readonly close = vi.fn();
}

class FakeMediaStream {
    public tracks: MediaStreamTrack[] = [];

    public getTracks(): MediaStreamTrack[] {
        return this.tracks;
    }

    public getAudioTracks(): MediaStreamTrack[] {
        return this.tracks.filter((track) => track.kind === 'audio');
    }

    public getVideoTracks(): MediaStreamTrack[] {
        return this.tracks.filter((track) => track.kind === 'video');
    }

    public addTrack(track: MediaStreamTrack): void {
        this.tracks.push(track);
    }
}

function callEnvelope(
    fromUserId: string,
    toUserId: string,
    callId: string,
    signal: unknown,
) {
    return {
        version: 1,
        event_id: `event-${Math.random()}`,
        type: 'call.signal' as const,
        occurred_at: new Date().toISOString(),
        data: {
            conversation_id: 'conversation-1',
            from_user_id: fromUserId,
            to_user_id: toUserId,
            call_id: callId,
            signal,
        },
    };
}

async function emitCallSignal(
    signal: unknown,
    callId = 'call-1',
): Promise<void> {
    useRealtimeStore().callSignals.push(
        callEnvelope('caller-1', 'receiver-1', callId, signal),
    );
    await nextTick();
}

describe('CallControls', () => {
    beforeEach(() => {
        mocks.userId = 'receiver-1';
        useRealtimeStore().callSignals.splice(0);
        useRealtimeStore().lastCallSignalError = null;
        mocks.sendCallSignal.mockClear();
        mocks.sendCallSignal.mockReturnValue('request-1');
        mocks.subscribe.mockClear();
        mocks.takeCallSignals.mockClear();
        FakePeerConnection.instances = [];
        FakePeerConnection.emitCandidateOnSetLocal = false;

        vi.stubGlobal('RTCPeerConnection', FakePeerConnection);
        vi.stubGlobal('MediaStream', FakeMediaStream);
        vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: {
                getUserMedia: vi.fn(async () => ({
                    getTracks: () => [],
                    getAudioTracks: () => [],
                    getVideoTracks: () => [],
                })),
            },
        });
    });

    it('does not render floating call icons on the conversation list without an active direct chat', () => {
        const wrapper = mount(CallControls, {
            props: {
                conversationId: '',
                recipientId: null,
                recipientName: '',
                enabled: false,
            },
        });

        expect(wrapper.find('[aria-label="Start voice call"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[aria-label="Start video call"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('.fixed').exists()).toBe(false);
        wrapper.unmount();
    });

    it('shows the caller identity with answer and decline actions, then answers the WebRTC offer', async () => {
        const wrapper = mount(CallControls, {
            props: {
                conversationId: 'conversation-1',
                recipientId: 'caller-1',
                recipientName: 'Caller Name',
                enabled: true,
            },
            global: {
                stubs: {
                    Button: {
                        inheritAttrs: false,
                        template:
                            '<button @click="$emit(\'click\')"><slot /></button>',
                    },
                    Phone: true,
                    PhoneOff: true,
                    Video: true,
                },
            },
        });

        await emitCallSignal({
            kind: 'ice',
            candidate: { candidate: 'candidate-before-offer' },
        });

        await emitCallSignal({
            kind: 'offer',
            description: { type: 'offer', sdp: 'audio offer' },
        });

        expect(wrapper.get('[role="alertdialog"]').text()).toContain(
            'Caller Name is calling you',
        );
        expect(wrapper.text()).toContain('Answer');
        expect(wrapper.text()).toContain('Decline');
        expect(mocks.subscribe).not.toHaveBeenCalled();

        await wrapper.get('[role="alertdialog"] button').trigger('click');
        await flushPromises();

        expect(FakePeerConnection.instances).toHaveLength(1);
        expect(FakePeerConnection.instances[0]?.remoteDescription?.type).toBe(
            'offer',
        );
        expect(
            FakePeerConnection.instances[0]?.addIceCandidate,
        ).toHaveBeenCalledWith({
            candidate: 'candidate-before-offer',
        });
        expect(mocks.sendCallSignal).toHaveBeenCalledWith(
            'conversation-1',
            'caller-1',
            'call-1',
            expect.objectContaining({
                kind: 'answer',
                description: expect.objectContaining({ type: 'answer' }),
            }),
        );
        wrapper.unmount();
    });

    it('starts an outgoing video call by creating and sending a WebRTC offer', async () => {
        mocks.userId = 'caller-1';
        FakePeerConnection.emitCandidateOnSetLocal = true;
        const wrapper = mount(CallControls, {
            props: {
                conversationId: 'conversation-1',
                recipientId: 'receiver-1',
                recipientName: 'Receiver Name',
                enabled: true,
            },
            global: {
                stubs: {
                    Button: {
                        inheritAttrs: false,
                        template:
                            '<button @click="$emit(\'click\')"><slot /></button>',
                    },
                    Phone: true,
                    PhoneOff: true,
                    Video: true,
                },
            },
        });

        await wrapper.findAll('button')[1]?.trigger('click');
        await flushPromises();

        expect(FakePeerConnection.instances).toHaveLength(1);
        expect(mocks.sendCallSignal).toHaveBeenCalledWith(
            'conversation-1',
            'receiver-1',
            expect.any(String),
            expect.objectContaining({
                kind: 'offer',
                description: expect.objectContaining({ type: 'offer' }),
            }),
        );
        expect(mocks.sendCallSignal.mock.calls[0]?.[3]).toEqual(
            expect.objectContaining({ kind: 'offer' }),
        );
        expect(mocks.sendCallSignal.mock.calls[1]?.[3]).toEqual({
            kind: 'ice',
            candidate: { candidate: 'local-candidate' },
        });
        expect(wrapper.text()).toContain('Calling Receiver Name');

        const callId = mocks.sendCallSignal.mock.calls[0]?.[2] as string;
        useRealtimeStore().callSignals.push(
            callEnvelope('receiver-1', 'caller-1', callId, {
                kind: 'answer',
                description: { type: 'answer', sdp: 'gateway-answer' },
            }),
        );
        await nextTick();
        await flushPromises();
        expect(wrapper.text()).toContain('Calling Receiver Name');

        useRealtimeStore().callSignals.push(
            callEnvelope('receiver-1', 'caller-1', callId, {
                kind: 'accepted',
            }),
        );
        await nextTick();
        expect(wrapper.text()).toContain('Connecting…');

        FakePeerConnection.instances[0]!.connectionState = 'connected';
        FakePeerConnection.instances[0]!.onconnectionstatechange?.();
        await nextTick();
        expect(wrapper.text()).toContain('Connected · 00:00');

        useRealtimeStore().callSignals.push(
            callEnvelope('receiver-1', 'caller-1', callId, { kind: 'hangup' }),
        );
        await nextTick();

        expect(FakePeerConnection.instances[0]?.close).toHaveBeenCalledOnce();
        expect(wrapper.find('[aria-label="End call"]').exists()).toBe(false);
        expect(wrapper.findAll('button')).toHaveLength(2);
        wrapper.unmount();
    });

    it('restarts the existing camera sender when the remote side requests video recovery', async () => {
        const audioTrack = {
            kind: 'audio',
            readyState: 'live',
            stop: vi.fn(),
        } as MediaStreamTrack;
        const videoTrack = {
            kind: 'video',
            readyState: 'live',
            stop: vi.fn(),
        } as MediaStreamTrack;
        const stream = new FakeMediaStream();
        stream.addTrack(audioTrack);
        stream.addTrack(videoTrack);
        vi.mocked(navigator.mediaDevices.getUserMedia).mockResolvedValue(
            stream as unknown as MediaStream,
        );
        const wrapper = mount(CallControls, {
            props: {
                conversationId: 'conversation-1',
                recipientId: 'caller-1',
                recipientName: 'Caller Name',
                enabled: true,
            },
            global: {
                stubs: {
                    Button: {
                        inheritAttrs: false,
                        template:
                            '<button @click="$emit(\'click\')"><slot /></button>',
                    },
                    Phone: true,
                    PhoneOff: true,
                    Video: true,
                },
            },
        });

        await wrapper.findAll('button')[1]?.trigger('click');
        await flushPromises();
        const callId = mocks.sendCallSignal.mock.calls[0]?.[2] as string;
        await emitCallSignal({ kind: 'video.recover' }, callId);
        await vi.waitFor(() => {
            expect(FakePeerConnection.instances[0]?.senders[1]?.replaceTrack)
                .toHaveBeenNthCalledWith(1, null);
            expect(FakePeerConnection.instances[0]?.senders[1]?.replaceTrack)
                .toHaveBeenNthCalledWith(2, videoTrack);
        });

        wrapper.unmount();
    });

    it('attaches caller video tracks even when the track event has no stream', async () => {
        mocks.userId = 'caller-1';
        const wrapper = mount(CallControls, {
            props: {
                conversationId: 'conversation-1',
                recipientId: 'receiver-1',
                recipientName: 'Receiver Name',
                enabled: true,
            },
            global: {
                stubs: {
                    Button: {
                        inheritAttrs: false,
                        template:
                            '<button @click="$emit(\'click\')"><slot /></button>',
                    },
                    Phone: true,
                    PhoneOff: true,
                    Video: true,
                },
            },
        });

        await wrapper.findAll('button')[1]?.trigger('click');
        await flushPromises();
        const track = {
            kind: 'video',
            muted: false,
            readyState: 'live',
            addEventListener: vi.fn(),
        } as unknown as MediaStreamTrack;
        FakePeerConnection.instances[0]?.ontrack?.({
            track,
            streams: [],
        } as unknown as RTCTrackEvent);
        await nextTick();

        const stream = wrapper.findAll('video')[0]?.element
            .srcObject as unknown as FakeMediaStream;
        expect(stream).toBeInstanceOf(FakeMediaStream);
        expect(stream.getTracks()).toEqual([track]);
        expect(wrapper.find('video[autoplay]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('waits for an incoming video track before requesting keyframe recovery', async () => {
        vi.useFakeTimers();
        mocks.userId = 'caller-1';
        const wrapper = mount(CallControls, {
            props: {
                conversationId: 'conversation-1',
                recipientId: 'receiver-1',
                recipientName: 'Receiver Name',
                enabled: true,
            },
            global: {
                stubs: {
                    Button: {
                        inheritAttrs: false,
                        template:
                            '<button @click="$emit(\'click\')"><slot /></button>',
                    },
                    Phone: true,
                    PhoneOff: true,
                    Video: true,
                },
            },
        });

        try {
            await wrapper.findAll('button')[1]?.trigger('click');
            await flushPromises();
            await vi.advanceTimersByTimeAsync(5000);

            expect(
                mocks.sendCallSignal.mock.calls.some(
                    ([, , , signal]) =>
                        (signal as { kind?: string }).kind === 'video.refresh',
                ),
            ).toBe(false);

            const connection = FakePeerConnection.instances[0]!;
            connection.connectionState = 'connected';
            connection.onconnectionstatechange?.();
            connection.ontrack?.({
                track: {
                    kind: 'video',
                    muted: false,
                    readyState: 'live',
                    addEventListener: vi.fn(),
                } as unknown as MediaStreamTrack,
                streams: [],
            } as unknown as RTCTrackEvent);
            await nextTick();

            expect(mocks.sendCallSignal.mock.calls).toEqual(
                expect.arrayContaining([
                    expect.arrayContaining([
                        'conversation-1',
                        'receiver-1',
                        expect.any(String),
                        { kind: 'video.refresh' },
                    ]),
                ]),
            );
        } finally {
            wrapper.unmount();
            vi.useRealTimers();
        }
    });

    it('shows Gateway rejection details instead of leaving the caller stuck on Calling', async () => {
        const wrapper = mount(CallControls, {
            props: {
                conversationId: 'conversation-1',
                recipientId: 'receiver-1',
                recipientName: 'Receiver Name',
                enabled: true,
            },
            global: {
                stubs: {
                    Button: {
                        inheritAttrs: false,
                        template:
                            '<button @click="$emit(\'click\')"><slot /></button>',
                    },
                    Phone: true,
                    PhoneOff: true,
                    Video: true,
                },
            },
        });

        await wrapper.findAll('button')[1]?.trigger('click');
        await flushPromises();
        useRealtimeStore().lastCallSignalError = {
            requestId: 'request-1',
            code: 'forbidden',
            message: 'You cannot call from this conversation.',
        };
        await nextTick();

        expect(wrapper.text()).toContain(
            'You cannot call from this conversation.',
        );
        expect(wrapper.text()).not.toContain('Calling Receiver Name');
        wrapper.unmount();
    });

    it('notifies the other participant when the local peer connection fails', async () => {
        mocks.userId = 'caller-1';
        const wrapper = mount(CallControls, {
            props: {
                conversationId: 'conversation-1',
                recipientId: 'receiver-1',
                recipientName: 'Receiver Name',
                enabled: true,
            },
            global: {
                stubs: {
                    Button: {
                        inheritAttrs: false,
                        template:
                            '<button @click="$emit(\'click\')"><slot /></button>',
                    },
                    Phone: true,
                    PhoneOff: true,
                    Video: true,
                },
            },
        });

        await wrapper.findAll('button')[1]?.trigger('click');
        await flushPromises();
        const connection = FakePeerConnection.instances[0];
        expect(connection).toBeDefined();

        connection!.connectionState = 'failed';
        connection!.onconnectionstatechange?.();

        expect(mocks.sendCallSignal).toHaveBeenLastCalledWith(
            'conversation-1',
            'receiver-1',
            expect.any(String),
            { kind: 'hangup' },
        );
        expect(connection!.close).toHaveBeenCalledOnce();
        wrapper.unmount();
    });
});
