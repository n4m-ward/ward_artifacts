<x-layouts.auth :title="'Autorizar '.$client->name">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="'Conectar '.$client->name"
            description="Este cliente MCP quer acessar o Ward Artifacts em seu nome."
        />

        <div class="space-y-3 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <p class="text-zinc-500 dark:text-zinc-400">Conectado como</p>
            <p class="font-medium">{{ $user->name }} · {{ '@'.$user->username }}</p>

            <flux:separator />

            <p class="text-zinc-500 dark:text-zinc-400">Ele vai poder:</p>
            <ul class="list-inside list-disc space-y-1">
                <li>publicar artifacts (sempre privados) e enviar novas versões</li>
                <li>ler, listar e renomear os artifacts que você pode ver</li>
                @if ($user->isAdmin())
                    <li>convidar usuários (porque você é admin)</li>
                @endif
            </ul>

            @if ($client->client_uri ?? null)
                <p class="break-all text-xs text-zinc-500">{{ $client->client_uri }}</p>
            @endif
        </div>

        <flux:text class="text-center text-xs">
            Você pode revogar este acesso quando quiser em Conexões MCP.
        </flux:text>

        <div class="flex gap-3">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <flux:button type="submit" class="w-full">Cancelar</flux:button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                @csrf
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <flux:button type="submit" variant="primary" class="w-full">Autorizar</flux:button>
            </form>
        </div>
    </div>
</x-layouts.auth>
