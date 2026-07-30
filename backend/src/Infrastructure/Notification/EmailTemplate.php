<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

final class EmailTemplate
{
    public static function render(
        string $subject,
        string $body,
        string $requestUrl,
    ): string {
        $subject = self::escape($subject);
        $requestUrl = self::escape($requestUrl);
        $body = self::linkify(self::escape($body));

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f3f5f9;color:#142033;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" '
            . 'style="width:100%;background:#f3f5f9;"><tr><td align="center" style="padding:32px 12px;">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" '
            . 'style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dde3ec;">'
            . '<tr><td style="padding:20px 28px;background:#f3f5f9;'
            . 'border-bottom:1px solid #dde3ec;color:#142033;font-family:Arial,sans-serif;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td width="4" bgcolor="#253d98" style="width:4px;background:#253d98;font-size:0;line-height:0;">&nbsp;</td>'
            . '<td style="padding:3px 0 3px 14px;color:#142033;font-family:Arial,sans-serif;">'
            . '<div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#6e7a8b;">АО «ЩЛЗ»</div>'
            . '<div style="padding-top:4px;font-size:18px;font-weight:bold;">Испытательный центр</div>'
            . '</td></tr></table></td></tr>'
            . '<tr><td style="padding:30px 28px;font-family:Arial,sans-serif;">'
            . '<h1 style="margin:0 0 20px;font-size:22px;line-height:30px;color:#142033;">' . $subject . '</h1>'
            . '<div style="font-size:15px;line-height:24px;color:#344054;">' . nl2br($body, false) . '</div>'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:28px;"><tr><td>'
            . '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="' . $requestUrl
            . '" style="height:46px;v-text-anchor:middle;width:220px" arcsize="50%" fillcolor="#253d98" stroke="f">'
            . '<w:anchorlock xmlns:w="urn:schemas-microsoft-com:office:word"/><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">Открыть заявку</center></v:roundrect><![endif]-->'
            . '<!--[if !mso]><!--><a href="' . $requestUrl . '" style="display:inline-block;padding:13px 24px;border-radius:23px;'
            . 'background:#253d98;color:#ffffff;font:bold 15px Arial,sans-serif;text-decoration:none;">Открыть заявку</a><!--<![endif]-->'
            . '</td></tr></table>'
            . '<p style="margin:24px 0 0;font:12px/18px Arial,sans-serif;color:#8a96a7;">Или перейдите по ссылке:<br>'
            . '<a href="' . $requestUrl . '" style="color:#253d98;word-break:break-all;">' . $requestUrl . '</a></p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function linkify(string $body): string
    {
        return (string) preg_replace_callback(
            '~https?://[^\s]+~u',
            static fn(array $match): string => '<a href="' . $match[0]
                . '" style="color:#253d98;text-decoration:underline;word-break:break-all;">' . $match[0] . '</a>',
            $body,
        );
    }
}
