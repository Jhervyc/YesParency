<?php
/**
 * utils/procurement_mode_helper.php
 *
 * Centralised procurement mode classification.
 * All mode-branching logic across the system should call these helpers
 * rather than doing ad-hoc string comparisons.
 *
 * Supported categories:
 *   'svp'              – Small Value Procurement (no encryption, no bid-session)
 *   'shopping'         – Shopping (no encryption, no bid-session)
 *   'public_bidding'   – Public Bidding and everything else (full two-envelope encrypted flow)
 */

if (!function_exists('get_procurement_category')) {

    /**
     * Classify a procurement mode string into one of three canonical categories.
     *
     * @param  string $mode  The raw procurement_mode value from the database.
     * @return string        'svp' | 'shopping' | 'public_bidding'
     */
    function get_procurement_category(string $mode): string
    {
        $lower = strtolower(trim($mode));

        if (str_contains($lower, 'small value') || $lower === 'svp') {
            return 'svp';
        }

        if (str_contains($lower, 'shopping')) {
            return 'shopping';
        }

        return 'public_bidding';
    }
}

if (!function_exists('is_quotation_mode')) {

    /**
     * Returns true when the procurement mode uses the quotation flow
     * (SVP or Shopping) — i.e. no encryption, no bid-session.
     *
     * @param  string $mode  The raw procurement_mode value from the database.
     * @return bool
     */
    function is_quotation_mode(string $mode): bool
    {
        $cat = get_procurement_category($mode);
        return ($cat === 'svp' || $cat === 'shopping');
    }
}

if (!function_exists('procurement_mode_label')) {

    /**
     * Returns a human-readable label for the category.
     *
     * @param  string $mode
     * @return string
     */
    function procurement_mode_label(string $mode): string
    {
        return match (get_procurement_category($mode)) {
            'svp'      => 'Small Value Procurement',
            'shopping' => 'Shopping',
            default    => 'Public Bidding',
        };
    }
}
