<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\CreditTransaction;
use App\Models\MediaAsset;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Boot;
use App\Support\Branding;
use App\Support\Media;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** Studio account screens: media library, credits, usage, API keys, settings (profile, password, 2FA) and support. */
class AccountController extends Controller
{
    /** Everything the account screens need, embedded in the Studio page. */
    public static function data(User $user): array
    {
        return [
            'media' => static::mediaItems($user),
            'credits' => static::credits($user),
            'usage' => static::usage($user),
            'api' => static::api($user),
            'settings' => static::settings($user),
            'tickets' => static::tickets($user),
        ];
    }

    public function state(Request $request)
    {
        return static::data($request->user()) + ['user' => Boot::me($request->user())];
    }

    // ------------------------------------------------------------------ media

    public static function mediaItems(User $user): array
    {
        $disk = Storage::disk('public');
        $items = collect();
        foreach (MediaAsset::where('user_id', $user->id)->latest()->get() as $a) {
            $items->push(['key' => 'u'.$a->id, 'id' => $a->id, 'kind' => $a->kind, 'name' => $a->name, 'url' => Media::url($a->path), 'path' => $a->path,
                'size' => static::bytes($a->size), 'date' => $a->created_at?->format('M j'), 'ts' => $a->created_at?->timestamp ?? 0, 'source' => 'Upload', 'deletable' => true]);
        }
        foreach ($user->projects()->latest()->get() as $p) {
            if ($p->output_path && $disk->exists($p->output_path)) {
                $items->push(['key' => 'r'.$p->id, 'kind' => 'video', 'name' => $p->name.'.mp4', 'url' => Media::url($p->output_path), 'path' => $p->output_path,
                    'size' => static::bytes($disk->size($p->output_path)), 'date' => $p->updated_at?->format('M j'), 'ts' => $p->updated_at?->timestamp ?? 0, 'source' => 'Render', 'deletable' => false]);
            }
            foreach ((array) $p->scenes as $i => $s) {
                foreach (['img' => 'image', 'clip' => 'video', 'audio' => 'audio'] as $field => $kind) {
                    $rel = $s[$field] ?? null;
                    if ($rel && ! str_starts_with($rel, 'media/') && $disk->exists($rel)) {
                        $items->push(['key' => $field.$p->id.'-'.($s['id'] ?? $i), 'kind' => $kind, 'name' => Str::limit($p->name, 28).' · scene '.($i + 1), 'url' => Media::url($rel), 'path' => $rel,
                            'size' => static::bytes($disk->size($rel)), 'date' => $p->updated_at?->format('M j'), 'ts' => $p->updated_at?->timestamp ?? 0, 'source' => $kind === 'audio' ? 'Voiceover' : 'AI', 'deletable' => false]);
                    }
                }
            }
        }

        return $items->unique('path')->sortByDesc('ts')->take(300)->values()->all();
    }

    public function upload(Request $request)
    {
        $request->validate(['file' => 'required|file|max:102400|mimetypes:image/png,image/jpeg,image/webp,image/gif,video/mp4,video/quicktime,video/webm,audio/mpeg,audio/wav,audio/x-wav,audio/mp4,audio/ogg']);
        $user = $request->user();
        $f = $request->file('file');
        $mime = (string) $f->getMimeType();
        $kind = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : 'audio');
        $quota = ($user->plan?->storage_gb ?? 1) * 1024 ** 3;
        abort_if(static::storageBytes($user) + $f->getSize() > $quota, 422, 'Storage full — delete some media or upgrade your plan.');
        $path = $f->storeAs('media/'.$user->id, Str::random(12).'.'.($f->guessExtension() ?: $f->getClientOriginalExtension() ?: 'bin'), 'public');
        MediaAsset::create(['user_id' => $user->id, 'name' => Str::limit($f->getClientOriginalName() ?: 'upload', 120, ''), 'path' => $path, 'kind' => $kind, 'size' => $f->getSize()]);
        Media::publish($path);

        return ['media' => static::mediaItems($user)];
    }

    public function deleteMedia(Request $request, MediaAsset $asset)
    {
        abort_unless($asset->user_id === $request->user()->id, 404);
        Media::delete($asset->path);
        $asset->delete();

        return ['media' => static::mediaItems($request->user())];
    }

    public static function storageBytes(User $user): int
    {
        $disk = Storage::disk('public');
        $bytes = (int) MediaAsset::where('user_id', $user->id)->sum('size');
        foreach ($user->projects()->whereNotNull('output_path')->pluck('output_path') as $p) {
            $bytes += $disk->exists($p) ? $disk->size($p) : 0;
        }

        return $bytes;
    }

    // ---------------------------------------------------------------- credits

    public static function credits(User $user): array
    {
        $plan = $user->plan;
        $month = CreditTransaction::where('user_id', $user->id)->where('created_at', '>=', now()->startOfMonth());

        return [
            'balance' => number_format($user->credits),
            'plan' => $plan?->name ?? 'Free',
            'planCredits' => number_format($plan?->credits ?? 0),
            'planPrice' => $plan && $plan->price > 0 ? '$'.rtrim(rtrim(number_format($plan->price, 2), '0'), '.').'/mo' : 'Free',
            'spentMonth' => number_format(-(int) (clone $month)->where('amount', '<', 0)->sum('amount')),
            'addedMonth' => number_format((int) (clone $month)->where('amount', '>', 0)->sum('amount')),
            'plans' => Plan::orderBy('sort')->orderBy('price')->get()->map(fn ($p) => ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'price' => $p->price,
                'priceLabel' => $p->price > 0 ? '$'.rtrim(rtrim(number_format($p->price, 2), '0'), '.') : 'Free', 'credits' => number_format($p->credits), 'current' => $p->id === $user->plan_id,
                'popular' => (bool) $p->popular])->values()->all(),
            'history' => CreditTransaction::where('user_id', $user->id)->latest('id')->limit(100)->get()->map(fn ($t) => ['id' => $t->id, 'reason' => $t->reason,
                'amount' => ($t->amount > 0 ? '+' : '').number_format($t->amount), 'positive' => $t->amount > 0, 'date' => $t->created_at?->format('M j, H:i')])->values()->all(),
            'payments' => Payment::where('user_id', $user->id)->latest('id')->limit(50)->get()->map(fn ($p) => ['id' => $p->id, 'ref' => $p->reference, 'item' => $p->item,
                'gateway' => $p->gateway, 'amount' => '$'.number_format((float) $p->amount, 2), 'status' => $p->status, 'date' => $p->created_at?->format('M j, Y'),
                'bank' => $p->gateway === 'Bank transfer' && $p->status === 'Pending' ? (array) (($p->meta ?? [])['instructions'] ?? []) : null])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ usage

    public static function usage(User $user): array
    {
        $since = now()->subDays(29)->startOfDay();
        $tx = CreditTransaction::where('user_id', $user->id)->where('created_at', '>=', $since)->where('amount', '<', 0)->get(['amount', 'reason', 'created_at']);
        $projects = $user->projects()->where('created_at', '>=', $since)->get(['created_at', 'status']);
        $days = collect(range(0, 29))->map(fn ($i) => $since->copy()->addDays($i));
        $spend = $days->map(fn (Carbon $d) => ['label' => $d->format('M j'), 'v' => -(int) $tx->filter(fn ($t) => $t->created_at->isSameDay($d))->sum('amount'),
            'videos' => $projects->filter(fn ($p) => $p->created_at->isSameDay($d))->count()])->values();
        $cat = fn (string $r) => match (true) {
            str_starts_with($r, 'Render') || str_starts_with($r, 'Assemble') => 'Rendering',
            str_starts_with($r, 'AI image') || str_starts_with($r, 'Character') || str_starts_with($r, 'Cinematic') => 'AI images',
            str_starts_with($r, 'AI video') => 'AI video clips',
            str_starts_with($r, 'Workflow') => 'Automations',
            str_starts_with($r, 'Agent') => 'AI agents',
            default => 'Other',
        };
        $total = max(1, -(int) $tx->sum('amount'));
        $by = $tx->groupBy(fn ($t) => $cat($t->reason))->map(fn ($g, $k) => ['label' => $k, 'v' => -(int) $g->sum('amount'), 'pct' => round(-$g->sum('amount') / $total * 100)])
            ->sortByDesc('v')->values();
        $keyIds = $user->apiKeys()->pluck('id');
        $storage = static::storageBytes($user);
        $quota = ($user->plan?->storage_gb ?? 1) * 1024 ** 3;

        return [
            'days' => $spend->all(), 'max' => max(1, $spend->max('v')), 'maxVideos' => max(1, $spend->max('videos')),
            'spent30' => number_format(-(int) $tx->sum('amount')), 'videos30' => $projects->count(), 'rendered30' => $projects->where('status', 'Completed')->count(),
            'apiCalls30' => ApiRequestLog::whereIn('api_key_id', $keyIds)->where('created_at', '>=', $since)->count(),
            'byCategory' => $by->all(),
            'storage' => static::bytes($storage), 'storageQuota' => ($user->plan?->storage_gb ?? 1).' GB', 'storagePct' => round(min(100, $storage / max(1, $quota) * 100), 1),
        ];
    }

    // -------------------------------------------------------------------- API

    public static function api(User $user): array
    {
        return [
            'allowed' => static::apiAllowed($user),
            'keys' => $user->apiKeys()->latest('id')->get()->map(fn (ApiKey $k) => ['id' => $k->id, 'name' => $k->name, 'masked' => $k->prefix.'••••'.$k->last4,
                'requests' => number_format($k->requests), 'lastUsed' => $k->last_used_at?->diffForHumans() ?? 'never', 'created' => $k->created_at?->format('M j, Y'),
                'revoked' => $k->revoked_at !== null])->values()->all(),
            'base' => url('/api'),
        ];
    }

    private static function apiAllowed(User $user): bool
    {
        return $user->isAdmin() || (bool) $user->plan?->api_access;
    }

    public function createKey(Request $request)
    {
        $user = $request->user();
        abort_unless(static::apiAllowed($user), 403, 'Your plan does not include API access. Upgrade to create keys.');
        $data = $request->validate(['name' => 'required|string|max:60']);
        abort_if($user->apiKeys()->whereNull('revoked_at')->count() >= 10, 422, 'You can have up to 10 active keys.');
        [, $plain] = ApiKey::issue($user, $data['name']);
        ActivityLog::record($user->email.' created API key "'.$data['name'].'"', 'api');

        return ['key' => $plain, 'api' => static::api($user)];
    }

    public function revokeKey(Request $request, ApiKey $key)
    {
        abort_unless($key->user_id === $request->user()->id, 404);
        $key->update(['revoked_at' => now()]);

        return ['api' => static::api($request->user())];
    }

    // --------------------------------------------------------------- settings

    public static function settings(User $user): array
    {
        return [
            'name' => $user->name, 'email' => $user->email, 'verified' => $user->email_verified_at !== null,
            'twoFactor' => $user->hasTwoFactor(), 'recoveryLeft' => count((array) $user->two_factor_recovery_codes),
            'twoFactorRequired' => $user->isAdmin() && static::adminsNeed2fa(),
            'prefs' => ['renderDone' => $user->pref('renderDone'), 'lowCredits' => $user->pref('lowCredits'), 'product' => $user->pref('product'), 'weekly' => $user->pref('weekly')],
            'isAdmin' => $user->isAdmin(),
        ];
    }

    public static function adminsNeed2fa(): bool
    {
        return (bool) (((array) Setting::get('toggles', []))['twofa'] ?? false);
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190|unique:users,email,'.$user->id]);
        $changed = strtolower($data['email']) !== strtolower($user->email);
        $user->forceFill(['name' => $data['name'], 'email' => $data['email']] + ($changed ? ['email_verified_at' => null] : []))->save();
        if ($changed && AuthController::verificationRequired()) {
            app(AuthController::class)->sendVerification($user);
        } elseif ($changed) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return ['user' => Boot::me($user->fresh()), 'settings' => static::settings($user->fresh()), 'verify' => $changed && AuthController::verificationRequired()];
    }

    public function password(Request $request)
    {
        $user = $request->user();
        $request->validate(['current_password' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(8)]]);
        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is wrong.']);
        }
        $user->forceFill(['password' => $request->input('password')])->save();
        Auth::logoutOtherDevices($request->input('password'));
        ActivityLog::record($user->email.' changed their password', 'auth');

        return ['ok' => true];
    }

    public function prefs(Request $request)
    {
        $data = $request->validate(['renderDone' => 'boolean', 'lowCredits' => 'boolean', 'product' => 'boolean', 'weekly' => 'boolean']);
        $user = $request->user();
        $user->forceFill(['prefs' => array_merge((array) $user->prefs, $data)])->save();

        return ['settings' => static::settings($user)];
    }

    public function destroy(Request $request)
    {
        $user = $request->user();
        $request->validate(['password' => 'required|string']);
        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Wrong password.']);
        }
        abort_if($user->isAdmin() && User::where('role', 'admin')->count() <= 1, 422, 'You are the only administrator. Make someone else an admin first.');
        foreach (MediaAsset::where('user_id', $user->id)->pluck('path') as $p) {
            Media::delete($p);
        }
        ActivityLog::record('Account deleted: '.$user->email, 'auth', 'WARNING');
        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ['ok' => true];
    }

    // -------------------------------------------------------------------- 2FA

    public function twoFactorSetup(Request $request)
    {
        $user = $request->user();
        $secret = Totp::secret();
        $request->session()->put('2fa.pending', encrypt($secret));
        $uri = Totp::uri($secret, $user->email, Branding::appName());

        return ['secret' => trim(chunk_split($secret, 4, ' ')), 'uri' => $uri, 'qr' => 'data:image/svg+xml;base64,'.base64_encode(Totp::qrSvg($uri))];
    }

    public function twoFactorConfirm(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $pending = $request->session()->get('2fa.pending');
        abort_unless($pending, 422, 'Start the setup again.');
        $secret = decrypt($pending);
        if (! Totp::verify($secret, (string) $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the time on your phone and try the newest code.']);
        }
        $codes = Totp::recoveryCodes();
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes), 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('2fa.pending');
        $request->session()->put('2fa.passed', true);
        ActivityLog::record($user->email.' turned on two-factor authentication', 'auth');

        return ['codes' => $codes, 'settings' => static::settings($user)];
    }

    public function twoFactorRecovery(Request $request)
    {
        $user = $request->user();
        $this->checkPassword($request);
        abort_unless($user->hasTwoFactor(), 422, 'Two-factor authentication is off.');
        $codes = Totp::recoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return ['codes' => $codes, 'settings' => static::settings($user)];
    }

    public function twoFactorDisable(Request $request)
    {
        $user = $request->user();
        $this->checkPassword($request);
        abort_if($user->isAdmin() && static::adminsNeed2fa(), 422, 'Two-factor authentication is required for administrators on this installation.');
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        ActivityLog::record($user->email.' turned off two-factor authentication', 'auth', 'WARNING');

        return ['settings' => static::settings($user)];
    }

    private function checkPassword(Request $request): void
    {
        $request->validate(['password' => 'required|string']);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Wrong password.']);
        }
    }

    // ---------------------------------------------------------------- support

    public static function tickets(User $user): array
    {
        // Administrators see every ticket here and answer as staff.
        return SupportTicket::with('user')->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))->latest('updated_at')->limit(100)->get()
            ->map(fn ($t) => static::ticket($t) + ['mine' => $t->user_id === $user->id])->values()->all();
    }

    public static function ticket(SupportTicket $t): array
    {
        return ['id' => $t->id, 'code' => '#'.(1000 + $t->id), 'subject' => $t->subject, 'category' => $t->category, 'status' => $t->status,
            'updated' => $t->updated_at?->diffForHumans(), 'user' => $t->user?->name, 'email' => $t->user?->email,
            'messages' => array_map(fn ($m) => $m + ['when' => isset($m['at']) ? Carbon::createFromTimestamp($m['at'])->diffForHumans() : ''], (array) $t->messages)];
    }

    public function openTicket(Request $request)
    {
        $data = $request->validate(['subject' => 'required|string|max:160', 'category' => 'nullable|string|max:40', 'message' => 'required|string|max:5000']);
        $user = $request->user();
        $t = SupportTicket::create(['user_id' => $user->id, 'subject' => $data['subject'], 'category' => $data['category'] ?: 'Question', 'status' => 'Open',
            'messages' => [['from' => 'user', 'name' => $user->name, 'body' => $data['message'], 'at' => time()]]]);
        $this->mailSupport($t, $data['message']);

        return ['tickets' => static::tickets($user)];
    }

    public function replyTicket(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        $staff = $user->isAdmin() && $ticket->user_id !== $user->id;
        abort_unless($ticket->user_id === $user->id || $user->isAdmin(), 404);
        $data = $request->validate(['message' => 'required|string|max:5000']);
        $ticket->update(['status' => $staff ? 'Answered' : 'Open', 'messages' => array_merge((array) $ticket->messages, [['from' => $staff ? 'staff' : 'user', 'name' => $user->name, 'body' => $data['message'], 'at' => time()]])]);
        if ($staff) {
            $app = Branding::appName();
            $this->mail($ticket->user->email, "Re: {$ticket->subject} [".'#'.(1000 + $ticket->id).']', "{$data['message']}\n\nReply in the Studio: ".url('/studio/support')."\n\n— {$app} support");
        } else {
            $this->mailSupport($ticket, $data['message']);
        }

        return ['tickets' => static::tickets($request->user()), 'ticket' => static::ticket($ticket->fresh())];
    }

    public function closeTicket(Request $request, SupportTicket $ticket)
    {
        abort_unless($ticket->user_id === $request->user()->id || $request->user()->isAdmin(), 404);
        $ticket->update(['status' => 'Closed']);

        return ['tickets' => static::tickets($request->user()), 'ticket' => static::ticket($ticket->fresh())];
    }

    private function mailSupport(SupportTicket $t, string $body): void
    {
        $to = Branding::whiteLabel()['support'] ?? null;
        if ($to && $to !== 'support@example.com') {
            $this->mail($to, '[#'.(1000 + $t->id).'] '.$t->subject, "From: {$t->user->name} <{$t->user->email}>\nCategory: {$t->category}\n\n{$body}\n\nAnswer it in the Studio → Support inbox: ".url('/studio/support'));
        }
        ActivityLog::record('Support ticket #'.(1000 + $t->id).' from '.$t->user->email.': '.$t->subject, 'support');
    }

    private function mail(string $to, string $subject, string $body): void
    {
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            ActivityLog::record('Could not send email to '.$to.': '.mb_substr($e->getMessage(), 0, 200), 'mail', 'ERROR');
        }
    }

    private static function bytes(int|float $b): string
    {
        return $b >= 1024 ** 3 ? round($b / 1024 ** 3, 2).' GB' : ($b >= 1024 ** 2 ? round($b / 1024 ** 2, 1).' MB' : max(1, round($b / 1024)).' KB');
    }
}
