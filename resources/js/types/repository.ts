export type ConnectionStatus = 'connected' | 'failed';

/**
 * Whether GitHub is pushing merges at us yet.
 *
 * There is no failed state: every way automatic setup can fail leaves the
 * owner in the same place, with the same instructions and the same retry.
 */
export type WebhookStatus = 'active' | 'manual_setup_required';

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
    webhook_status: WebhookStatus;
    webhook_url: string;
    webhook_last_delivery_at: string | null;
    /** Whether we created the hook, and can therefore maintain it ourselves. */
    manages_hook: boolean;
    last_synced_at: string | null;
};
