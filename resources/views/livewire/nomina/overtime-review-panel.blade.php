<div>
    @if ($showOvertimeDecisionModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
                <p class="text-xs font-semibold uppercase tracking-[0.16em]">Decisión auditada</p>
                <h2 class="mt-1 text-xl font-black text-slate-950">{{ $overtimeDecision === 'partial' ? 'Aprobar tramo parcial' : 'Decidir tramo completo' }}</h2>
                <p class="mt-2 rounded-xl bg-slate-100 px-3 py-2 text-sm font-bold text-slate-800">{{ $overtimeCandidateSummary }}</p>
                <form wire:submit="submitOvertimeDecision" class="mt-4 space-y-4">
                    @if ($overtimeDecision === 'partial')
                        <div class="grid gap-3 sm:grid-cols-2"><input type="datetime-local" wire:model="overtimeApprovedStartsAt"><input type="datetime-local" wire:model="overtimeApprovedEndsAt"></div>
                    @endif
                    <textarea wire:model="overtimeDecisionReason" rows="3" maxlength="500" placeholder="Motivo obligatorio" class="w-full rounded-xl border-slate-300"></textarea>
                    @error('overtimeDecisionReason') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                    <div class="flex justify-end gap-2"><button type="button" wire:click="closeOvertimeDecisionModal">Cancelar</button><button type="submit">Confirmar</button></div>
                </form>
            </div>
        </div>
    @endif
</div>
