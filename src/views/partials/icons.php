<?php
declare(strict_types=1);

/**
 * MoeRNG icon set.
 *
 * Single locked icon system for the whole project: outline style, 1.5px stroke,
 * currentColor, 24x24 viewBox. Emoji are never used as functional icons because
 * they render inconsistently across platforms and cannot inherit theme colors.
 *
 * Sizes: 16 (inline text) / 20 (inside buttons) / 24 (standalone).
 *
 * Usage:  <?= icon('dice', 24) ?>
 */

if (!function_exists('icon')) {

    /**
     * @param string $name  Icon key, see $paths below
     * @param int    $size  16 | 20 | 24
     * @param string $class Extra CSS classes
     * @param string $label Accessible label; when empty the icon is aria-hidden
     */
    function icon(string $name, int $size = 20, string $class = '', string $label = ''): string
    {
        static $paths = [
            // Randomness / shuffling
            'dice' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.2" fill="currentColor" stroke="none"/><circle cx="15.5" cy="15.5" r="1.2" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.2" fill="currentColor" stroke="none"/>',
            'shuffle' => '<path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/>',

            // Structure
            'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2.5h8a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
            'folder-tree' => '<path d="M3 5a1 1 0 0 1 1-1h3l1.2 1.5H13a1 1 0 0 1 1 1V10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M17 14h4"/><path d="M17 18h4"/><path d="M8 11v7h6"/><path d="M8 14h6"/>',

            // Performance
            'zap' => '<path d="M13 2L4.5 13.5H11l-1 8.5L19.5 10H13z"/>',

            // Security
            'shield' => '<path d="M12 3l7.5 3v5.5c0 4.5-3 8.2-7.5 9.5-4.5-1.3-7.5-5-7.5-9.5V6z"/><path d="M9.2 12l2 2 3.6-3.8"/>',

            // Storage
            'cloud' => '<path d="M17.5 18.5H7a4 4 0 0 1-.6-7.95 5.5 5.5 0 0 1 10.7-1.2A3.85 3.85 0 0 1 17.5 18.5z"/>',

            // Media
            'image' => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="M21 15.5l-4.5-4.2L7 20"/>',

            // State
            'check' => '<path d="M4.5 12.5l5 5 10-11"/>',
            'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.2l2.8 2.8L16.2 9"/>',
            'x' => '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
            'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6"/><path d="M15 9l-6 6"/>',
            'alert' => '<path d="M12 4.5L21 19.5H3z"/><path d="M12 10v4"/><circle cx="12" cy="16.8" r="0.9" fill="currentColor" stroke="none"/>',

            // Actions
            'upload' => '<path d="M12 15.5V4"/><path d="M7.5 8.5L12 4l4.5 4.5"/><path d="M4 15.5V18a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2.5"/>',
            'trash' => '<path d="M4 7h16"/><path d="M9.5 7V5.5a1.5 1.5 0 0 1 1.5-1.5h2a1.5 1.5 0 0 1 1.5 1.5V7"/><path d="M6.5 7l1 12a2 2 0 0 0 2 2h5a2 2 0 0 0 2-2l1-12"/>',
            'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/>',

            // Navigation / admin
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
            'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 6.2a3 3 0 0 1 0 5.6"/><path d="M17.5 14.3a5 5 0 0 1 3.5 4.7"/>',
            'key' => '<circle cx="8" cy="14" r="4"/><path d="M11 11l8-8"/><path d="M15 3l3 3"/>',
            'settings' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.2"/><path d="M12 19.3v2.2"/><path d="M4.9 4.9l1.6 1.6"/><path d="M17.5 17.5l1.6 1.6"/><path d="M2.5 12h2.2"/><path d="M19.3 12h2.2"/><path d="M4.9 19.1l1.6-1.6"/><path d="M17.5 6.5l1.6-1.6"/>',
            'logout' => '<path d="M15 4h3a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-3"/><path d="M10 12H3"/><path d="M6 8.5L2.5 12 6 15.5"/>',
            'edit' => '<path d="M4 16.5V20h3.5L18 9.5 14.5 6z"/><path d="M13.5 6.5l3 3"/>',
            'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4-4"/>',
            'link' => '<path d="M10 14l4-4"/><path d="M8.5 11.5l-1.5 1.5a3.5 3.5 0 0 0 5 5l1.5-1.5"/><path d="M15.5 12.5l1.5-1.5a3.5 3.5 0 0 0-5-5L9.5 8.5"/>',
            'refresh' => '<path d="M3.5 12a8.5 8.5 0 0 1 14.2-6.3"/><path d="M21 4v4h-4"/><path d="M20.5 12a8.5 8.5 0 0 1-14.2 6.3"/><path d="M3 20v-4h4"/>',
            'chevron-right' => '<path d="M9 5l7 7-7 7"/>',
            'chevron-left' => '<path d="M15 5l-7 7 7 7"/>',
            'sun' => '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5"/><path d="M12 19.5V22"/><path d="M2 12h2.5"/><path d="M19.5 12H22"/><path d="M4.9 4.9l1.8 1.8"/><path d="M17.3 17.3l1.8 1.8"/><path d="M4.9 19.1l1.8-1.8"/><path d="M17.3 6.7l1.8-1.8"/>',
            'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4 7 7 0 1 0 20 14.5z"/>',
            'sparkles' => '<path d="M12 3l1.8 4.7L18.5 9.5 13.8 11.3 12 16l-1.8-4.7L5.5 9.5 11.2 7.7z"/><path d="M18 14l.8 2.2 2.2.8-2.2.8L18 20l-.8-2.2-2.2-.8 2.2-.8z"/>',

            // v1.0.32 UX additions
            'external-link' => '<path d="M14 4h6v6"/><path d="M20 4L10.5 13.5"/><path d="M20 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4"/>',
            'menu' => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
            'loader' => '<path d="M12 3v3"/><path d="M12 18v3"/><path d="M3 12h3"/><path d="M18 12h3"/><path d="M5.6 5.6l2.1 2.1"/><path d="M16.3 16.3l2.1 2.1"/><path d="M18.4 5.6l-2.1 2.1"/><path d="M7.7 16.3l-2.1 2.1"/>',
            'image-off' => '<rect x="4" y="5" width="16" height="14" rx="2.5"/><path d="M4 5.5L20 18.5"/><path d="M20 5.5L4 18.5"/>',
            'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>',
            'download' => '<path d="M12 4v11"/><path d="M7.5 11L12 15.5 16.5 11"/><path d="M4 19.5h16"/>',
            'markdown' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 15.5V9.5l2.5 3 2.5-3v6"/><path d="M15.5 9.5v6"/><path d="M15.5 12.5h2"/>',
            'grid' => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>',
            // v1.1.0-beta.4 audit trail
            'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1"/><path d="M9 10h6"/><path d="M9 14h6"/><path d="M9 18h3.5"/>',
        ];

        $body = $paths[$name] ?? $paths['alert'];

        $cls  = 'ic' . ($class !== '' ? ' ' . $class : '');
        $a11y = $label !== ''
            ? ' role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
            : ' aria-hidden="true" focusable="false"';

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"%s>%s</svg>',
            $cls,
            $size,
            $size,
            $a11y,
            $body
        );
    }
}
