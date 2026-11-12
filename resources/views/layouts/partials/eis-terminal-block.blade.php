@php
    $blockedTerminals = collect();
    if (auth()->check() && \Illuminate\Support\Facades\Schema::hasTable('eis_settings')) {
        $blockedTerminals = \Modules\EIS\Services\Terminal\TerminalBlockService::visibleTo(auth()->user());
    }
@endphp
<div id="eis-terminal-block-notices">
@foreach ($blockedTerminals as $blockedTerminal)
    <div class="alert alert-danger no-print" role="alert">
        <strong>EIS terminal {{ $blockedTerminal->device_id }} blocked.</strong>
        {{ $blockedTerminal->blocking_reason ?: \Modules\EIS\Services\Terminal\TerminalBlockService::DEFAULT_REASON }}
    </div>
@endforeach
</div>
<script>
window.addEventListener('load', function () {
    var notices = document.getElementById('eis-terminal-block-notices');
    var pending = false;
    var timer = window.setInterval(function () {
        if (document.hidden || pending) {
            return;
        }
        pending = true;
        fetch(@json(route('eis.terminal-block-status')), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (response) {
                if (!response.ok) {
                    if (response.status === 401 || response.status === 403) {
                        window.clearInterval(timer);
                    }
                    throw new Error('EIS terminal block status unavailable (' + response.status + ').');
                }
                return response.json();
            })
            .then(function (data) {
                if (!Array.isArray(data.terminals)) {
                    throw new Error('Invalid EIS terminal block status.');
                }
                var alerts = data.terminals.map(function (terminal) {
                    var alert = document.createElement('div');
                    alert.className = 'alert alert-danger no-print';
                    alert.setAttribute('role', 'alert');
                    alert.textContent = 'EIS terminal ' + terminal.device_id + ' blocked. ' + terminal.message;
                    return alert;
                });
                notices.replaceChildren.apply(notices, alerts);
            })
            .catch(function (error) {
                console.warn(error.message);
            })
            .finally(function () { pending = false; });
    }, 15000);
});
</script>
