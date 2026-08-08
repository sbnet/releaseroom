export type ConnectionStatus = 'connected' | 'failed';

/**
 * The GitHub repository a project reads its merged pull requests from.
 *
 * The token itself never crosses to the client: `token_last_four` is all
 * the owner gets back, and it is only there so they can recognize which
 * credential they stored.
 */
export type RepositoryConnection = {
    owner: string;
    name: string;
    full_name: string;
    url: string;
    is_private: boolean;
    default_branch: string;
    token_last_four: string;
    token_expires_at: string | null;
    status: ConnectionStatus;
    error_message: string | null;
    last_checked_at: string;
    created_at: string | null;
};
