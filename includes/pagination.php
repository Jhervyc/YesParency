<?php
// ── Compact pagination ───────────────────────────────────────────────────────
// Renders a fixed number of buttons regardless of page count:
//   desktop:  ‹ 1 … 7 [8] 9 … 24 ›
//   phone:    ‹ 1 … [8] … 24 ›        (≤480px, see dashboard.css)
// $query holds the other params to keep in each link (filters, search); empty
// values are dropped. Each link has a real href (no-JS fallback) plus
// data-page, which js/ajax-list.js reads.
if (!function_exists('render_pagination')) {
    function render_pagination(int $page, int $total_pages, array $query = []): string
    {
        if ($total_pages <= 1) return '';

        $page  = max(1, min($page, $total_pages));
        $query = array_filter($query, fn($v) => $v !== null && $v !== '');

        $href = function (int $p) use ($query) {
            return '?' . htmlspecialchars(http_build_query(['page' => $p] + $query));
        };
        $link = function (int $p, string $label, string $cls = '', string $aria = '') use ($href) {
            $aria = $aria !== '' ? ' aria-label="' . $aria . '"' : '';
            return '<a href="' . $href($p) . '" data-page="' . $p . '" class="page-link ' . trim($cls) . '"' . $aria . '>' . $label . '</a>';
        };

        // Desktop page set: first, last, current ±1 (all pages when ≤7)
        if ($total_pages <= 7) {
            $desktop = range(1, $total_pages);
        } elseif ($page <= 4) {
            $desktop = [1, 2, 3, 4, 5, $total_pages];
        } elseif ($page >= $total_pages - 3) {
            $desktop = [1, $total_pages - 4, $total_pages - 3, $total_pages - 2, $total_pages - 1, $total_pages];
        } else {
            $desktop = [1, $page - 1, $page, $page + 1, $total_pages];
        }

        // Phone page set (subset of desktop): first, current, last (all when ≤5).
        // A gap of exactly one page shows that page instead of "…".
        if ($total_pages <= 5) {
            $mobile = $desktop;
        } else {
            $mobile = array_values(array_unique([1, $page, $total_pages]));
            foreach ([[1, $page], [$page, $total_pages]] as [$a, $b]) {
                if ($b - $a === 2) $mobile[] = $a + 1;
            }
            sort($mobile);
        }

        $html  = '<nav class="pagination" aria-label="Pagination">';
        $html .= $link(max(1, $page - 1), '<i class="bi bi-chevron-left"></i>', 'page-prev' . ($page <= 1 ? ' disabled' : ''), 'Previous page');

        $prev_d = 0;
        $prev_m = 0;
        foreach ($desktop as $p) {
            $in_mobile = in_array($p, $mobile, true);
            if ($p - $prev_d > 1)                  $html .= '<span class="page-ellipsis page-ellipsis--lg">…</span>';
            if ($in_mobile && $p - $prev_m > 1)    $html .= '<span class="page-ellipsis page-ellipsis--sm">…</span>';

            $cls = ($p === $page ? 'active' : '') . ($in_mobile ? '' : ' page-hide-sm');
            $html .= $link($p, (string)$p, $cls, 'Page ' . $p);

            $prev_d = $p;
            if ($in_mobile) $prev_m = $p;
        }

        $html .= $link(min($total_pages, $page + 1), '<i class="bi bi-chevron-right"></i>', 'page-next' . ($page >= $total_pages ? ' disabled' : ''), 'Next page');
        $html .= '</nav>';
        return $html;
    }
}
