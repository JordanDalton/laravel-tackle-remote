<?php

namespace TackleRemote\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class BrowserQr
{
    public static function render(string $url): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::L,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'svgAddXmlHeader' => true,
        ]);

        return (new QRCode($options))->render($url);
    }
}
