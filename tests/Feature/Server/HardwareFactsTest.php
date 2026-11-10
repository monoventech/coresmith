<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Server\HardwareFacts;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSSHTransport;

test("the facts read a machine's processor, its cores, the system's own name and the memory installed in it", function () {
    $facts = (new HardwareFacts)->parse("cpu_model=AMD Ryzen 7 6800H with Radeon Graphics\ncpu_cores=8\nos=Ubuntu 24.04.5 LTS\ninstalled_mb=32768\n");

    expect($facts)->toBe([
        'cpu_model' => 'AMD Ryzen 7 6800H with Radeon Graphics',
        'cpu_physical_cores' => 8,
        'os_pretty' => 'Ubuntu 24.04.5 LTS',
        'memory_installed_mb' => 32768,
    ]);
});

test('a fact the machine does not report is left unknown rather than guessed', function () {
    expect((new HardwareFacts)->parse("cpu_model=\ncpu_cores=0\nos=Debian GNU/Linux 12 (bookworm)\ninstalled_mb=0\n"))->toBe([
        'cpu_model' => null,
        'cpu_physical_cores' => null,
        'os_pretty' => 'Debian GNU/Linux 12 (bookworm)',
        'memory_installed_mb' => null,
    ]);
});

test('text from the machine is kept to one short, printable line', function () {
    $facts = (new HardwareFacts)->parse("cpu_model=Intel(R)  Xeon(R)\tGold \e[31m6230\n" . 'os=' . str_repeat('x', 300) . "\n");

    expect($facts['cpu_model'])->toBe('Intel(R) Xeon(R) Gold [31m6230')
        ->and(mb_strlen((string) $facts['os_pretty']))->toBe(120);
});

test('the installed memory adds up every module the firmware reports, in whatever unit it uses', function () {
    $dmidecode = "Memory Device\n\tSize: 8 GB\nMemory Device\n\tSize: No Module Installed\nMemory Device\n\tSize: 16384 MB\nMemory Device\n\tSize: 1 TB\n";
    $awk = (string) preg_replace('/^.*dmidecode -t 17 2>\/dev\/null \| /s', '', (new HardwareFacts)->command());
    $process = Process::fromShellCommandline($awk);
    $process->setInput($dmidecode);
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('installed_mb=' . (8 * 1024 + 16384 + 1024 * 1024));
});

test('the facts are read with root, since the firmware tables need it', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'ssh_user' => 'deploy']);
    $transport = (new FakeSSHTransport)->respondTo('lscpu', "cpu_model=Neoverse-N1\ncpu_cores=4\nos=Ubuntu 24.04.5 LTS\ninstalled_mb=0\n");

    $facts = (new HardwareFacts)->read((new SSHConnection($server, $transport))->connect());

    expect($facts['cpu_model'])->toBe('Neoverse-N1')
        ->and($transport->commands()[0])->toStartWith('sudo bash -c ');
});

test('facts are stored with when they were read, leaving out what is unknown', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 18:00:00'));

    expect((new HardwareFacts)->toMeta(['cpu_model' => 'Neoverse-N1', 'cpu_physical_cores' => null, 'os_pretty' => null, 'memory_installed_mb' => null]))
        ->toBe(['cpu_model' => 'Neoverse-N1', 'facts_at' => '2026-11-10T18:00:00+00:00']);
});

test('facts are read again when missing, and once a day after that', function (?string $readAt, bool $due) {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 18:00:00'));
    $server = Server::factory()->make(['meta' => $readAt === null ? null : ['facts_at' => $readAt]]);

    expect((new HardwareFacts)->due($server))->toBe($due);
})->with([
    'never read' => [null, true],
    'read an hour ago' => ['2026-11-10T17:00:00+00:00', false],
    'read a day ago' => ['2026-11-09T18:00:00+00:00', true],
]);
