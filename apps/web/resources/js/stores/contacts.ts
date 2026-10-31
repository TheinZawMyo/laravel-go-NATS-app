import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { ApiError, apiRequest } from '@/lib/http';
import type {
    Contact,
    ContactRequest,
    PublicUser,
    UserBlock,
    UserRelationship,
} from '@/types';

function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
        return error.message;
    }

    if (error instanceof Error) {
        return error.message;
    }

    return 'Something went wrong. Please try again.';
}

/**
 * The address book, the pending requests and the block list. They are loaded
 * together because every surface that shows one of them (the people panel, the
 * group member picker) needs the others to decide what actions to offer.
 */
export const useContactsStore = defineStore('contacts', () => {
    const contacts = ref<Contact[]>([]);
    const incomingRequests = ref<ContactRequest[]>([]);
    const outgoingRequests = ref<ContactRequest[]>([]);
    const blocks = ref<UserBlock[]>([]);
    const searchResults = ref<PublicUser[]>([]);

    const loading = ref(false);
    const searching = ref(false);
    const error = ref<string | null>(null);

    /**
     * Bumped on every search so a slow response for an older term can never
     * overwrite the results of the term the user is typing right now.
     */
    let searchRequest = 0;

    const pendingIncomingCount = computed(() => incomingRequests.value.length);

    function contactFor(username: string): Contact | undefined {
        return contacts.value.find(
            (contact) => contact.user.username === username,
        );
    }

    function isBlocked(username: string): boolean {
        return blocks.value.some((block) => block.user.username === username);
    }

    function relationshipFor(username: string): UserRelationship {
        if (contactFor(username) !== undefined) {
            return 'contact';
        }

        if (
            outgoingRequests.value.some(
                (request) => request.receiver.username === username,
            )
        ) {
            return 'request_sent';
        }

        if (
            incomingRequests.value.some(
                (request) => request.sender.username === username,
            )
        ) {
            return 'request_received';
        }

        return 'none';
    }

    async function fetchContacts(): Promise<void> {
        const response = await apiRequest<{ data: Contact[] }>(
            '/api/v1/contacts',
        );

        contacts.value = response.data;
    }

    async function fetchRequests(): Promise<void> {
        const [incoming, outgoing] = await Promise.all([
            apiRequest<{ data: ContactRequest[] }>(
                '/api/v1/contact-requests?direction=incoming',
            ),
            apiRequest<{ data: ContactRequest[] }>(
                '/api/v1/contact-requests?direction=outgoing',
            ),
        ]);

        incomingRequests.value = incoming.data;
        outgoingRequests.value = outgoing.data;
    }

    async function fetchBlocks(): Promise<void> {
        const response = await apiRequest<{ data: UserBlock[] }>(
            '/api/v1/blocks',
        );

        blocks.value = response.data;
    }

    async function fetchAll(): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            await Promise.all([
                fetchContacts(),
                fetchRequests(),
                fetchBlocks(),
            ]);
        } catch (requestError) {
            error.value = errorMessage(requestError);
        } finally {
            loading.value = false;
        }
    }

    async function searchUsers(term: string): Promise<void> {
        const query = term.trim().replace(/^@/, '');
        const request = ++searchRequest;

        if (query === '') {
            searchResults.value = [];
            searching.value = false;

            return;
        }

        searching.value = true;
        error.value = null;

        try {
            const response = await apiRequest<{ data: PublicUser[] }>(
                `/api/v1/users?q=${encodeURIComponent(query)}`,
            );

            if (request === searchRequest) {
                searchResults.value = response.data;
            }
        } catch (requestError) {
            if (request === searchRequest) {
                error.value = errorMessage(requestError);
                searchResults.value = [];
            }
        } finally {
            if (request === searchRequest) {
                searching.value = false;
            }
        }
    }

    function clearSearch(): void {
        searchRequest += 1;
        searchResults.value = [];
        searching.value = false;
    }

    async function sendRequest(
        username: string,
        message: string | null = null,
    ): Promise<ContactRequest> {
        const response = await apiRequest<{ data: ContactRequest }>(
            '/api/v1/contact-requests',
            {
                method: 'POST',
                body: { username, message },
            },
        );

        outgoingRequests.value = [response.data, ...outgoingRequests.value];

        return response.data;
    }

    async function acceptRequest(request: ContactRequest): Promise<void> {
        await apiRequest<{ data: ContactRequest }>(
            `/api/v1/contact-requests/${request.id}/accept`,
            { method: 'POST' },
        );

        incomingRequests.value = incomingRequests.value.filter(
            (current) => current.id !== request.id,
        );

        await fetchContacts();
    }

    async function rejectRequest(request: ContactRequest): Promise<void> {
        await apiRequest<{ data: ContactRequest }>(
            `/api/v1/contact-requests/${request.id}/reject`,
            { method: 'POST' },
        );

        incomingRequests.value = incomingRequests.value.filter(
            (current) => current.id !== request.id,
        );
    }

    async function cancelRequest(request: ContactRequest): Promise<void> {
        await apiRequest<{ data: ContactRequest }>(
            `/api/v1/contact-requests/${request.id}/cancel`,
            { method: 'POST' },
        );

        outgoingRequests.value = outgoingRequests.value.filter(
            (current) => current.id !== request.id,
        );
    }

    async function renameContact(
        username: string,
        nickname: string | null,
    ): Promise<void> {
        const response = await apiRequest<{ data: Contact }>(
            `/api/v1/contacts/${username}`,
            {
                method: 'PATCH',
                body: { nickname },
            },
        );

        contacts.value = contacts.value.map((contact) =>
            contact.id === response.data.id ? response.data : contact,
        );
    }

    async function removeContact(username: string): Promise<void> {
        await apiRequest<void>(`/api/v1/contacts/${username}`, {
            method: 'DELETE',
        });

        contacts.value = contacts.value.filter(
            (contact) => contact.user.username !== username,
        );
    }

    async function blockUser(
        username: string,
        reason: string | null = null,
    ): Promise<void> {
        const response = await apiRequest<{ data: UserBlock }>(
            '/api/v1/blocks',
            {
                method: 'POST',
                body: { username, reason },
            },
        );

        blocks.value = [response.data, ...blocks.value];
        contacts.value = contacts.value.filter(
            (contact) => contact.user.username !== username,
        );
    }

    async function unblockUser(username: string): Promise<void> {
        await apiRequest<void>(`/api/v1/blocks/${username}`, {
            method: 'DELETE',
        });

        blocks.value = blocks.value.filter(
            (block) => block.user.username !== username,
        );
    }

    return {
        contacts,
        incomingRequests,
        outgoingRequests,
        blocks,
        searchResults,
        loading,
        searching,
        error,
        pendingIncomingCount,
        contactFor,
        isBlocked,
        relationshipFor,
        fetchAll,
        fetchContacts,
        fetchRequests,
        fetchBlocks,
        searchUsers,
        clearSearch,
        sendRequest,
        acceptRequest,
        rejectRequest,
        cancelRequest,
        renameContact,
        removeContact,
        blockUser,
        unblockUser,
    };
});
