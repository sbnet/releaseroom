export type CandidateState = 'pending' | 'dismissed';

/**
 * A merged pull request waiting for the owner's ruling.
 *
 * The body is deliberately absent: the list is for triage, and the link back
 * to GitHub carries the full text.
 */
export type PullRequestCandidate = {
    id: number;
    number: number;
    title: string;
    author_login: string | null;
    author_avatar_url: string | null;
    labels: string[];
    merged_at: string;
    html_url: string;
    state: CandidateState;
};

export type CandidateCounts = {
    pending: number;
    dismissed: number;
};

/** One page of a Laravel paginator, as Inertia receives it. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
