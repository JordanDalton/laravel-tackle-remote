<?php

namespace TackleRemote\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * Renders a URL as an ANSI QR code for the terminal, so a phone can join the
 * session by pointing its camera at the screen. Fail-soft: any rendering
 * problem returns an empty string — the URL is always printed alongside.
 */
class TerminalQr
{
    public static function render(string $url): string
    {
        try {
            $options = new QROptions([
                'outputType' => QROutputInterface::STRING_TEXT,
                'eccLevel' => EccLevel::L,
                'addQuietzone' => true,
                'quietzoneSize' => 1,
                'textLineStart' => '  ',
            ]);

            return (new QRCode($options))->render($url);
        } catch (Throwable) {
            return '';
        }
    }
}
