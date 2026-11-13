@php
    $blockedTerminals = collect();
    if (auth()->check() && \Illuminate\Support\Facades\Schema::hasTable('eis_settings')) {
        $blockedTerminals = \Modules\EIS\Services\Terminal\TerminalBlockService::visibleTo(auth()->user());
    }
    $blockedTerminals = $blockedTerminals->map(fn ($terminal) => [
        'device_id' => $terminal->device_id,
        'message' => $terminal->blocking_reason
            ?: \Modules\EIS\Services\Terminal\TerminalBlockService::DEFAULT_REASON,
    ])->values();
@endphp
<script>
window.addEventListener('load', function () {
    var lastMessage = '';
    function showBlockedTerminals(terminals) {
        var message = terminals.map(function (terminal) {
            return 'EIS terminal ' + terminal.device_id + ' blocked. ' + terminal.message;
        }).join('\n\n');
        if (message && message !== lastMessage) {
            toastr.error(message, 'EIS terminal blocked', {escapeHtml: true});
        }
        lastMessage = message;
    }
    showBlockedTerminals(@json($blockedTerminals));
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
                showBlockedTerminals(data.terminals);
            })
            .catch(function (error) {
                console.warn(error.message);
            })
            .finally(function () { pending = false; });
    }, 15000);
});
</script>
