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
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<title>' . $subject . '</title></head>'
            . '<body style="margin:0;padding:0;background-color:#f3f5f9;color:#142033;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">'
            . $subject . ' — уведомление Портала Испытательного центра.</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f3f5f9" '
            . 'style="width:100%;background-color:#f3f5f9;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff" '
            . 'style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #dde3ec;border-collapse:separate;">'
            . '<tr><td style="padding:18px 24px;border-bottom:1px solid #e7ebf0;font-family:Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td width="34" height="34" align="center" valign="middle" bgcolor="#253d98" '
            . 'style="width:34px;height:34px;background-color:#253d98;color:#ffffff;font:700 11px/34px Arial,sans-serif;text-align:center;">ИЦ</td>'
            . '<td valign="middle" style="padding-left:11px;color:#142033;font-family:Arial,sans-serif;">'
            . '<div style="font-size:14px;line-height:18px;font-weight:bold;mso-line-height-rule:exactly;">АО «ЩЛЗ»</div>'
            . '<div style="font-size:10px;line-height:14px;letter-spacing:.7px;text-transform:uppercase;color:#7a8698;'
            . 'mso-line-height-rule:exactly;">Испытательный центр</div></td>'
            . '<td align="right" valign="middle" style="font:10px/14px Arial,sans-serif;color:#7a8698;text-transform:uppercase;'
            . 'letter-spacing:.6px;">Портал ИЦ</td></tr></table></td></tr>'
            . '<tr><td style="padding:28px 24px 30px;font-family:Arial,sans-serif;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td bgcolor="#e8ebfb" '
            . 'style="padding:5px 10px;background-color:#e8ebfb;color:#29449f;font:700 10px/14px Arial,sans-serif;'
            . 'letter-spacing:.5px;text-transform:uppercase;">Уведомление портала</td></tr></table>'
            . '<h1 style="margin:14px 0 14px;font-size:21px;line-height:28px;font-weight:bold;color:#142033;'
            . 'mso-line-height-rule:exactly;">' . $subject . '</h1>'
            . '<div style="font-size:14px;line-height:22px;color:#344054;mso-line-height-rule:exactly;">'
            . nl2br($body, false) . '</div>'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:24px;"><tr><td>'
            . '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="' . $requestUrl
            . '" style="height:40px;v-text-anchor:middle;width:184px" arcsize="50%" fillcolor="#253d98" stroke="f">'
            . '<w:anchorlock xmlns:w="urn:schemas-microsoft-com:office:word"/><center style="color:#ffffff;font-family:Arial,sans-serif;'
            . 'font-size:13px;font-weight:bold;">Открыть заявку</center></v:roundrect><![endif]-->'
            . '<!--[if !mso]><!--><a href="' . $requestUrl . '" style="display:inline-block;padding:11px 20px;border-radius:20px;'
            . 'background-color:#253d98;color:#ffffff;font:bold 13px/18px Arial,sans-serif;text-decoration:none;">Открыть заявку</a><!--<![endif]-->'
            . '</td></tr></table>'
            . '</td></tr>'
            . '<tr><td bgcolor="#f8f9fb" style="padding:14px 24px;background-color:#f8f9fb;border-top:1px solid #e7ebf0;'
            . 'font:10px/16px Arial,sans-serif;color:#7a8698;">Это автоматическое уведомление. Отвечать на него не нужно.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function linkify(string $body): string
    {
        return (string) preg_replace_callback(
            '~(?:Ссылка на (отчёт|заключение):\s*)?(https?://[^\s]+)~u',
            static function (array $match): string {
                $url = $match[2];
                $label = $match[1] !== ''
                    ? 'Скачать ' . $match[1]
                    : $url;

                return '<a href="' . $url
                    . '" style="color:#253d98;text-decoration:underline;word-break:break-word;">' . $label . '</a>';
            },
            $body,
        );
    }
}
