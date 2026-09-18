<?php

declare(strict_types=1);

namespace Olisa;

/**
 * The participant-facing email template, rendered to HTML and plain text.
 *
 * Email is not the web. The markup here is deliberately twenty years out of
 * date — nested tables, inline styles, no flexbox, no grid — because Outlook
 * on Windows renders through Word's HTML engine and Gmail strips most of what
 * a <style> block contains. What survives everywhere is a table with a fixed
 * max width and styles written onto each element.
 *
 * The <style> block is therefore used for one thing only: the media queries
 * and dark-mode overrides that cannot be expressed inline. Every rule in it is
 * a refinement of something that already looks right without it, so a client
 * that discards the block still gets a readable message.
 *
 * Width: a 600px content column, which is the widest that fits the Outlook
 * reading pane without a horizontal scrollbar, collapsing to fluid width with
 * larger touch targets under 620px.
 */
final class EmailTemplate
{
    /** Merge fields an author may use in the subject, preheader or body. */
    public const MERGE_FIELDS = [
        '{{name}}'      => 'The applicant’s first name, or “there” when unknown',
        '{{reference}}' => 'Their application reference, e.g. TP-WL-7K4D92',
        '{{trial}}'     => 'The trial they applied to',
        '{{email}}'     => 'The address the message is going to',
    ];

    private const INK     = '#201e1d';
    private const INK_2   = '#605d5d';
    private const INK_3   = '#7d7979';
    private const BG      = '#f3f2f2';
    private const SURFACE = '#ffffff';
    private const LINE    = '#d7d3d3';
    private const ACCENT  = '#2f9d6c';

    /** Web fonts do not load in most clients — this is the fallback stack. */
    private const FONT = "Archivo,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    /**
     * @param array<string, mixed> $message   subject, preheader, body, cta_label, cta_url
     * @param array<string, mixed> $recipient email, name, reference, trial
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(array $message, array $recipient): array
    {
        $subject   = self::merge((string) ($message['subject'] ?? ''), $recipient);
        $preheader = self::merge((string) ($message['preheader'] ?? ''), $recipient);
        $body      = self::merge((string) ($message['body'] ?? ''), $recipient);
        $ctaLabel  = trim((string) ($message['cta_label'] ?? ''));
        $ctaUrl    = self::merge(trim((string) ($message['cta_url'] ?? '')), $recipient);

        if ($ctaUrl !== '' && filter_var($ctaUrl, FILTER_VALIDATE_URL) === false) {
            $ctaUrl = '';
        }

        return [
            'subject' => $subject,
            'html'    => self::html($subject, $preheader, $body, $ctaLabel, $ctaUrl, $recipient),
            'text'    => self::text($body, $ctaLabel, $ctaUrl, $recipient),
        ];
    }

    /**
     * Substitutes merge fields. Values are inserted raw here and escaped later
     * by the renderer — escaping twice would show &amp; in someone's name.
     *
     * @param array<string, mixed> $recipient
     */
    public static function merge(string $text, array $recipient): string
    {
        $name = trim((string) ($recipient['name'] ?? ''));
        // First name only: "Dear Mrs Adeyemi-Okonkwo" reads like a form letter,
        // which is exactly what it is, but it does not need to announce itself.
        $first = $name === '' ? 'there' : (explode(' ', $name)[0] ?: 'there');

        $trial = (string) ($recipient['trial'] ?? '');

        return strtr($text, [
            '{{name}}'      => $first,
            '{{reference}}' => (string) ($recipient['reference'] ?? ''),
            '{{trial}}'     => $trial !== '' ? Trials::name($trial) : '',
            '{{email}}'     => (string) ($recipient['email'] ?? ''),
        ]);
    }

    // ------------------------------------------------------------------ HTML

    /** @param array<string, mixed> $recipient */
    private static function html(
        string $subject,
        string $preheader,
        string $body,
        string $ctaLabel,
        string $ctaUrl,
        array $recipient
    ): string {
        $org       = Config::str('app.name', 'Trial Path');
        $reference = (string) ($recipient['reference'] ?? '');
        $contact   = self::contactAddress();
        $font      = self::FONT;

        $h  = '<!doctype html><html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" '
            . 'xmlns:o="urn:schemas-microsoft-com:office:office"><head>';
        $h .= '<meta charset="utf-8">';
        $h .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $h .= '<meta http-equiv="X-UA-Compatible" content="IE=edge">';
        // Stops iOS Mail shrinking the whole message to fit, which makes 15px
        // body text render at about 11px.
        $h .= '<meta name="x-apple-disable-message-reformatting">';
        $h .= '<meta name="color-scheme" content="light dark">';
        $h .= '<meta name="supported-color-schemes" content="light dark">';
        $h .= '<title>' . Util::e($subject) . '</title>';
        // Outlook renders images at 120dpi unless told otherwise; this keeps the
        // spacer and rule heights honest.
        $h .= '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings>'
            . '<o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->';
        $h .= '<style>' . self::css() . '</style>';
        $h .= '</head>';

        $h .= '<body style="margin:0;padding:0;width:100%!important;background-color:' . self::BG . ';'
            . '-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%">';

        // Preheader: the grey line of text the inbox shows after the subject.
        // Left empty it fills itself with "View in browser" or the first words
        // of the header, so it is always written, then hidden.
        $h .= '<div class="preheader" style="display:none;font-size:1px;line-height:1px;max-height:0;'
            . 'max-width:0;opacity:0;overflow:hidden;mso-hide:all">'
            . Util::e($preheader !== '' ? $preheader : $subject)
            // Zero-width joiners stop clients pulling body text in after it.
            . str_repeat('&#847;&zwnj;&nbsp;', 60) . '</div>';

        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'class="body-bg" style="background-color:' . self::BG . ';width:100%;border-collapse:collapse">'
            . '<tr><td align="center" class="gutter" style="padding:28px 12px">';

        // Outlook ignores max-width, so it gets a real fixed-width table.
        $h .= '<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            . 'border="0" align="center"><tr><td><![endif]-->';

        $h .= '<table role="presentation" class="wrap" width="100%" cellpadding="0" cellspacing="0" '
            . 'border="0" style="width:100%;max-width:600px;margin:0 auto;border-collapse:collapse;'
            . 'background-color:' . self::SURFACE . ';border:1px solid ' . self::LINE . '">';

        // ---------------------------------------------------------- masthead
        $h .= '<tr><td class="masthead" style="background-color:' . self::INK . ';padding:22px 32px">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse"><tr>';
        $h .= '<td width="38" style="width:38px;vertical-align:middle">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse"><tr><td width="38" height="38" align="center" '
            . 'style="width:38px;height:38px;background-color:' . self::ACCENT . ';font-family:' . $font . ';'
            . 'font-size:14px;font-weight:bold;color:#ffffff;text-align:center;line-height:38px">TP</td>'
            . '</tr></table></td>';
        $h .= '<td style="padding-left:12px;vertical-align:middle;font-family:' . $font . '">'
            . '<div class="brand-name" style="font-family:' . $font . ';font-size:13px;font-weight:bold;'
            . 'letter-spacing:0.07em;color:#ffffff;text-transform:uppercase">' . Util::e($org) . '</div>'
            . '<div class="brand-sub" style="font-family:' . $font . ';font-size:11px;letter-spacing:0.14em;'
            . 'color:#9b9797;text-transform:uppercase">Clinical trials</div></td>';
        $h .= '</tr></table></td></tr>';

        // A single accent rule under the masthead, in place of a hero image —
        // images are blocked by default in most clients and this is not.
        $h .= '<tr><td style="font-size:0;line-height:0;height:3px;background-color:' . self::ACCENT . '">&nbsp;</td></tr>';

        // -------------------------------------------------------------- body
        $h .= '<tr><td class="pad" style="padding:32px">';
        $h .= self::blocksHtml($body);

        if ($ctaLabel !== '' && $ctaUrl !== '') {
            $h .= self::button($ctaLabel, $ctaUrl);
        }

        $h .= '</td></tr>';

        // ------------------------------------------------------------ footer
        $h .= '<tr><td class="pad-footer" style="padding:22px 32px 26px;background-color:#faf9f9;'
            . 'border-top:1px solid ' . self::LINE . '">';

        if ($reference !== '') {
            $h .= '<p style="margin:0 0 10px;font-family:' . $font . ';font-size:12px;line-height:1.6;'
                . 'color:' . self::INK_2 . '">Your application reference is '
                . '<strong style="color:' . self::INK . '">' . Util::e($reference) . '</strong>. '
                . 'Quote it if you contact us about this message.</p>';
        }

        $h .= '<p style="margin:0 0 10px;font-family:' . $font . ';font-size:12px;line-height:1.6;'
            . 'color:' . self::INK_3 . '">You are receiving this because you completed a screening '
            . 'form with ' . Util::e($org) . '. Please do not send health information by email — '
            . 'reply asking for a call instead.</p>';

        $h .= '<p style="margin:0;font-family:' . $font . ';font-size:12px;line-height:1.6;color:'
            . self::INK_3 . '">' . Util::e($org);
        if ($contact !== '') {
            $h .= ' · <a href="mailto:' . Util::e($contact) . '" style="color:' . self::INK_2
                . ';text-decoration:underline">' . Util::e($contact) . '</a>';
        }
        $h .= '</p></td></tr>';

        $h .= '</table>';
        $h .= '<!--[if mso]></td></tr></table><![endif]-->';
        $h .= '</td></tr></table></body></html>';

        return $h;
    }

    /**
     * Everything that cannot be said inline: the phone breakpoint, dark mode,
     * and the handful of client resets that stop Outlook and Gmail from
     * rewriting the layout.
     */
    private static function css(): string
    {
        return <<<CSS
        img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}
        table{border-collapse:collapse!important;mso-table-lspace:0;mso-table-rspace:0}
        body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
        /* Apple and Outlook.com both turn plain references and dates into links. */
        a[x-apple-data-detectors],.unstyle-auto-detected-links a,.aBn{
          color:inherit!important;text-decoration:none!important;font-size:inherit!important;
          font-family:inherit!important;font-weight:inherit!important;line-height:inherit!important;
          border-bottom:0!important}
        /* Gmail clips a message it thinks is a quoted reply. */
        .im{color:inherit!important}

        @media only screen and (max-width:620px){
          .gutter{padding:0!important}
          /* Edge-to-edge on a phone: a 12px margin either side wastes a tenth
             of the reading width and gains nothing. */
          .wrap{width:100%!important;max-width:100%!important;border-left:0!important;border-right:0!important}
          .masthead{padding:18px 20px!important}
          .pad{padding:24px 20px!important}
          .pad-footer{padding:18px 20px 22px!important}
          .p,.li{font-size:16px!important;line-height:1.65!important}
          .h2{font-size:19px!important}
          .brand-name{font-size:12px!important}
          /* Full-width tap target, comfortably over the 44px minimum. */
          .btn-wrap{width:100%!important}
          .btn-cell{display:block!important;width:100%!important;text-align:center!important;
            padding:16px 20px!important}
          .btn-link{display:block!important;font-size:16px!important}
        }

        @media (prefers-color-scheme:dark){
          .body-bg{background-color:#16110f!important}
          .wrap{background-color:#211c1a!important;border-color:#3a3330!important}
          .pad-footer{background-color:#1b1614!important;border-top-color:#3a3330!important}
          .p,.li,.h2,.quote{color:#ece8e6!important}
          .meta,.foot{color:#a9a29e!important}
          .rule{border-top-color:#3a3330!important}
        }
        CSS;
    }

    /**
     * A bulletproof button. The table carries the background so Outlook fills
     * the whole box rather than only the text, and VML gives Outlook 2007–2019
     * the same padded rectangle the rest of the world gets from the table.
     */
    private static function button(string $label, string $url): string
    {
        $font = self::FONT;
        $safeUrl = Util::e($url);

        $html  = '<table role="presentation" class="btn-wrap" cellpadding="0" cellspacing="0" border="0" '
               . 'style="border-collapse:collapse;margin:28px 0 4px"><tr>';
        $html .= '<td class="btn-cell" align="center" bgcolor="' . self::ACCENT . '" '
               . 'style="background-color:' . self::ACCENT . ';padding:15px 30px;mso-padding-alt:0">';
        $html .= '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" '
               . 'xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $safeUrl . '" '
               . 'style="height:48px;v-text-anchor:middle;width:260px" arcsize="0%" stroke="f" '
               . 'fillcolor="' . self::ACCENT . '"><w:anchorlock/><center style="color:#ffffff;'
               . 'font-family:' . $font . ';font-size:15px;font-weight:bold">' . Util::e($label)
               . '</center></v:roundrect><![endif]-->';
        $html .= '<!--[if !mso]><!--><a class="btn-link" href="' . $safeUrl . '" '
               . 'style="font-family:' . $font . ';font-size:15px;font-weight:bold;color:#ffffff;'
               . 'text-decoration:none;display:inline-block;line-height:1.2">' . Util::e($label)
               . '</a><!--<![endif]-->';
        $html .= '</td></tr></table>';

        return $html;
    }

    // ------------------------------------------------------- body formatting

    /**
     * Turns the author's plain text into email HTML.
     *
     * The accepted markup is deliberately tiny — headings, bullets, a quote, a
     * rule, bold and links. A coordinator writing to applicants needs those and
     * nothing else, and every construct left out is one that cannot render
     * badly in a client no one here can test against.
     */
    private static function blocksHtml(string $body): string
    {
        $font = self::FONT;
        $html = '';

        foreach (preg_split('/\R{2,}/', trim($body)) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            if (preg_match('/^-{3,}$/', $block)) {
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
                       . 'style="border-collapse:collapse;margin:26px 0"><tr><td class="rule" '
                       . 'style="border-top:1px solid ' . self::LINE . ';font-size:0;line-height:0">&nbsp;</td>'
                       . '</tr></table>';
                continue;
            }

            if (str_starts_with($block, '## ')) {
                $html .= '<h2 class="h2" style="margin:26px 0 10px;font-family:' . $font . ';font-size:18px;'
                       . 'font-weight:bold;line-height:1.3;color:' . self::INK . ';letter-spacing:-0.01em">'
                       . self::inline(substr($block, 3)) . '</h2>';
                continue;
            }

            if (str_starts_with($block, '> ')) {
                $quote = preg_replace('/^> ?/m', '', $block) ?? $block;
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
                       . 'style="border-collapse:collapse;margin:18px 0"><tr>'
                       . '<td style="border-left:3px solid ' . self::ACCENT . ';padding:4px 0 4px 16px">'
                       . '<p class="quote" style="margin:0;font-family:' . $font . ';font-size:15px;'
                       . 'line-height:1.6;color:' . self::INK . '">' . self::inline($quote) . '</p>'
                       . '</td></tr></table>';
                continue;
            }

            $lines = preg_split('/\R/', $block) ?: [];
            $isList = $lines !== [] && count(array_filter(
                $lines,
                static fn(string $line): bool => (bool) preg_match('/^\s*[-*]\s+/', $line)
            )) === count($lines);

            if ($isList) {
                // A table rather than <ul>: Outlook adds unpredictable indents
                // to list elements, and the bullet cell here is exact.
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
                       . 'style="border-collapse:collapse;margin:14px 0">';
                foreach ($lines as $line) {
                    $item = preg_replace('/^\s*[-*]\s+/', '', $line) ?? $line;
                    $html .= '<tr>'
                           . '<td width="18" valign="top" class="li" style="width:18px;padding:4px 0 4px 2px;'
                           . 'font-family:' . $font . ';font-size:15px;line-height:1.65;color:' . self::ACCENT
                           . '">&bull;</td>'
                           . '<td valign="top" class="li" style="padding:4px 0;font-family:' . $font . ';'
                           . 'font-size:15px;line-height:1.65;color:' . self::INK . '">'
                           . self::inline($item) . '</td></tr>';
                }
                $html .= '</table>';
                continue;
            }

            $html .= '<p class="p" style="margin:0 0 16px;font-family:' . $font . ';font-size:15px;'
                   . 'line-height:1.65;color:' . self::INK . '">'
                   . nl2br(self::inline($block), false) . '</p>';
        }

        return $html;
    }

    /**
     * Inline markup: **bold** and [label](https://…).
     *
     * Links are lifted out before escaping so the URL can be validated in its
     * original form, then put back already built — escaping first would turn
     * every & in a query string into &amp; before filter_var sees it.
     */
    private static function inline(string $text): string
    {
        $links = [];

        $withPlaceholders = preg_replace_callback(
            '/\[([^\]\n]{1,160})\]\(\s*(https?:\/\/[^\s)]{1,600})\s*\)/',
            static function (array $m) use (&$links): string {
                if (filter_var($m[2], FILTER_VALIDATE_URL) === false) {
                    return $m[1];
                }
                $token = "\x00L" . count($links) . "\x00";
                $links[$token] = '<a href="' . Util::e($m[2]) . '" style="color:' . self::ACCENT
                    . ';text-decoration:underline;font-weight:bold">' . Util::e($m[1]) . '</a>';
                return $token;
            },
            $text
        ) ?? $text;

        $escaped = Util::e($withPlaceholders);

        $escaped = preg_replace(
            '/\*\*(?=\S)(.+?)(?<=\S)\*\*/s',
            '<strong style="font-weight:bold;color:' . self::INK . '">$1</strong>',
            $escaped
        ) ?? $escaped;

        // A merge field that resolved to nothing leaves its emphasis markers
        // wrapped around empty space. Drop them rather than print "****".
        $escaped = preg_replace('/\*\*\s*\*\*/', '', $escaped) ?? $escaped;

        return strtr($escaped, $links);
    }

    // ------------------------------------------------------------ plain text

    /**
     * The text/plain alternative. Not an afterthought: it is what screen
     * readers in some clients announce, what plain-text-only recipients see,
     * and what spam filters compare the HTML against — a message with no text
     * part scores worse than one with a good one.
     *
     * @param array<string, mixed> $recipient
     */
    private static function text(string $body, string $ctaLabel, string $ctaUrl, array $recipient): string
    {
        $org       = Config::str('app.name', 'Trial Path');
        $reference = (string) ($recipient['reference'] ?? '');
        $contact   = self::contactAddress();

        $lines = [];
        foreach (preg_split('/\R{2,}/', trim($body)) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^-{3,}$/', $block)) {
                $lines[] = str_repeat('-', 48);
                $lines[] = '';
                continue;
            }
            if (str_starts_with($block, '## ')) {
                $heading = self::plainInline(substr($block, 3));
                $lines[] = strtoupper($heading);
                $lines[] = str_repeat('-', min(60, max(3, mb_strlen($heading))));
                $lines[] = '';
                continue;
            }
            $block = preg_replace('/^> ?/m', '', $block) ?? $block;
            $block = preg_replace('/^\s*[-*]\s+/m', '  * ', $block) ?? $block;
            $lines[] = self::plainInline($block);
            $lines[] = '';
        }

        if ($ctaLabel !== '' && $ctaUrl !== '') {
            $lines[] = $ctaLabel . ': ' . $ctaUrl;
            $lines[] = '';
        }

        $lines[] = str_repeat('-', 48);
        if ($reference !== '') {
            $lines[] = 'Your application reference is ' . $reference . '.';
        }
        $lines[] = 'You are receiving this because you completed a screening form';
        $lines[] = 'with ' . $org . '.';
        $lines[] = 'Please do not send health information by email — reply asking';
        $lines[] = 'for a call instead.';
        if ($contact !== '') {
            $lines[] = '';
            $lines[] = 'Contact: ' . $contact;
        }

        // CRLF throughout: bare LF in a mail body is a protocol violation some
        // MTAs silently repair and others do not.
        return str_replace("\n", "\r\n", rtrim(implode("\n", $lines)) . "\n");
    }

    /** Strips the inline markup, keeping link URLs visible in brackets. */
    private static function plainInline(string $text): string
    {
        $text = preg_replace('/\[([^\]\n]{1,160})\]\(\s*(https?:\/\/[^\s)]{1,600})\s*\)/', '$1 ($2)', $text) ?? $text;
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '$1', $text) ?? $text;
        return preg_replace('/\*\*\s*\*\*/', '', $text) ?? $text;
    }

    private static function contactAddress(): string
    {
        foreach (['mail.reply_to', 'mail.to', 'mail.from'] as $key) {
            $value = Config::str($key);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }
        return '';
    }
}
