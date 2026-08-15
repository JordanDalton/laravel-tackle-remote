<?php

use TackleRemote\Support\RemoteState;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/tackle-remote-state-'.uniqid();
    $this->state = new RemoteState($this->dir);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('gitignores the state dir so host-app file watchers skip it', function () {
    expect(file_get_contents($this->dir.'/.gitignore'))->toBe("*\n");
});

it('appends events and reads them back from a cursor', function () {
    $this->state->emit('user', ['text' => 'hello']);
    $this->state->emit('text', ['delta' => 'hi ']);
    $this->state->emit('text', ['delta' => 'there']);

    $first = $this->state->eventsAfter(0);

    expect($first['events'])->toHaveCount(3)
        ->and($first['events'][0]['type'])->toBe('user')
        ->and($first['cursor'])->toBe(3);

    $this->state->emit('turn_done');

    $second = $this->state->eventsAfter($first['cursor']);

    expect($second['events'])->toHaveCount(1)
        ->and($second['events'][0]['type'])->toBe('turn_done')
        ->and($second['cursor'])->toBe(4);
});

it('queues inbox messages and pops them oldest-first', function () {
    $this->state->pushMessage('first');
    usleep(2000);
    $this->state->pushMessage('second');

    expect($this->state->popMessage()['text'])->toBe('first')
        ->and($this->state->popMessage()['text'])->toBe('second')
        ->and($this->state->popMessage())->toBeNull();
});

it('round-trips a question and its answer', function () {
    $id = $this->state->ask('Run migrations?', ['yes' => 'Yes', 'no' => 'No'], 'Careful now');

    expect($this->state->pendingQuestion()['label'])->toBe('Run migrations?')
        ->and($this->state->takeAnswer($id))->toBeNull();

    $this->state->answer($id, 'yes');

    expect($this->state->takeAnswer($id))->toBe('yes')
        ->and($this->state->pendingQuestion())->toBeNull();
});

it('writes and reads session state atomically', function () {
    expect($this->state->state())->toBe(['status' => 'starting']);

    $this->state->putState(['status' => 'idle', 'session' => 'web']);

    expect($this->state->state())->toBe(['status' => 'idle', 'session' => 'web']);
});

it('survives malformed event lines', function () {
    $this->state->emit('user', ['text' => 'ok']);
    file_put_contents($this->dir.'/events.jsonl', "not json\n", FILE_APPEND);
    $this->state->emit('turn_done');

    $result = $this->state->eventsAfter(0);

    expect($result['events'])->toHaveCount(2)
        ->and($result['cursor'])->toBe(3);
});

it('queues commands alongside text messages', function () {
    $this->state->pushMessage('hello');
    usleep(2000);
    $this->state->pushCommand('clear');

    expect($this->state->popMessage()['text'])->toBe('hello')
        ->and($this->state->popMessage()['command'])->toBe('clear')
        ->and($this->state->popMessage())->toBeNull();
});

it('clears the event log so cursors reset', function () {
    $this->state->emit('user', ['text' => 'one']);
    $this->state->emit('user', ['text' => 'two']);

    $before = $this->state->eventsAfter(0);
    $this->state->clearEvents();
    $this->state->emit('cleared');

    $after = $this->state->eventsAfter($before['cursor']);

    expect($before['cursor'])->toBe(2)
        ->and($after['cursor'])->toBe(1)
        ->and($this->state->eventsAfter(0)['events'][0]['type'])->toBe('cleared');
});

it('stores and serves image attachments', function () {
    $id = $this->state->storeAttachment('photo.jpg', 'fake-jpeg-bytes');

    expect($id)->toEndWith('.jpg')
        ->and($this->state->attachmentPath($id))->not->toBeNull()
        ->and(file_get_contents($this->state->attachmentPath($id)))->toBe('fake-jpeg-bytes');
});

it('rejects non-image attachment types', function () {
    $this->state->storeAttachment('evil.php', '<?php echo 1;');
})->throws(InvalidArgumentException::class, 'Unsupported image type');

it('rejects oversized attachments', function () {
    $this->state->storeAttachment('big.png', str_repeat('x', 5 * 1024 * 1024 + 1));
})->throws(InvalidArgumentException::class, 'between 1 byte and 5 MB');

it('confines attachment lookups to the attachments directory', function () {
    file_put_contents($this->dir.'/state.json', '{}');

    expect($this->state->attachmentPath('../state.json'))->toBeNull()
        ->and($this->state->attachmentPath('/etc/hosts'))->toBeNull()
        ->and($this->state->attachmentPath('missing.jpg'))->toBeNull();
});

it('round-trips a message with image references', function () {
    $id = $this->state->storeAttachment('photo.png', 'bytes');
    $this->state->pushMessage('what is this?', [$id]);

    $message = $this->state->popMessage();

    expect($message['text'])->toBe('what is this?')
        ->and($message['images'])->toBe([$id]);
});

it('clears attachments', function () {
    $id = $this->state->storeAttachment('photo.png', 'bytes');
    $this->state->clearAttachments();

    expect($this->state->attachmentPath($id))->toBeNull();
});

it('round-trips the command roster', function () {
    $this->state->putCommands([['name' => 'clear', 'description' => 'Start fresh']]);

    expect($this->state->commands())->toBe([['name' => 'clear', 'description' => 'Start fresh']])
        ->and((new RemoteState($this->dir.'-empty'))->commands())->toBe([]);
});

it('searches the file index with prefix matches ranked first', function () {
    $this->state->putFiles([
        'app/Models/User.php',
        'app/Http/Controllers/UserController.php',
        'config/users.php',
        'routes/web.php',
    ]);

    expect($this->state->searchFiles('user'))->toBe([
        'app/Models/User.php',
        'app/Http/Controllers/UserController.php',
        'config/users.php',
    ])->and($this->state->searchFiles('app/'))->toBe([
        'app/Models/User.php',
        'app/Http/Controllers/UserController.php',
    ])->and($this->state->searchFiles(''))->toHaveCount(4)
        ->and($this->state->searchFiles('nope'))->toBe([])
        ->and($this->state->searchFiles('user', 1))->toBe(['app/Models/User.php']);
});
