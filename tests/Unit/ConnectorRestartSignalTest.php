<?php

use TackleRemote\Support\ConnectorRestartSignal;

it('detects only restart signals written after the worker starts', function () {
    $path = sys_get_temp_dir().'/tackle-restart-'.uniqid().'/connect.restart';
    $worker = new ConnectorRestartSignal($path);

    expect($worker->requested())->toBeFalse();

    (new ConnectorRestartSignal($path))->request();

    expect($worker->requested())->toBeTrue()
        ->and((new ConnectorRestartSignal($path))->requested())->toBeFalse();

    @unlink($path);
    @rmdir(dirname($path));
});
