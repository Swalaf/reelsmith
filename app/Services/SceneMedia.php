<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates the media for one scene — its still image, optional motion clip and voiceover —
 * and records the files on the scene (`img`, `clip`, `audio`, relative to the public disk).
 */
class SceneMedia
{
    public function __construct(private AiGateway $ai = new AiGateway) {}

    /** Try this provider slug first (with fallback to the rest). */
    public function prefer(?string $slug): static
    {
        $this->ai->prefer = $slug;

        return $this;
    }

    public function dir(Project $project): string
    {
        $dir = 'projects/'.$project->id;
        Storage::disk('public')->makeDirectory($dir);

        return $dir;
    }

    /**
     * Generate (or regenerate) the scene visual using the scene's source setting.
     *
     * @return array{scene: array, provider: string}
     */
    public function visual(Project $project, array $scene, ?string $style = null): array
    {
        $disk = Storage::disk('public');
        $dir = $this->dir($project);
        $prompt = trim(($scene['prompt'] ?? '') ?: ($scene['narration'] ?? $project->name));
        $brand = $project->user?->brandKit() ?? [];
        $full = $prompt.'. '.($style ?: 'Cinematic, high quality, professional lighting').'. No text, no captions, no watermark.'
            .(! empty($brand['c0']) ? ' Colour palette hint: '.$brand['c0'].' and '.($brand['c1'] ?? '').'.' : '');

        $providers = [];
        $imgRel = $dir.'/scene-'.$scene['id'].'-'.Str::lower(Str::random(6)).'.png';
        $wantVideo = ($scene['src'] ?? '') === 'AI Video';

        // A still is always useful: it's the fallback frame, the thumbnail, and Runway's input image.
        if (! $wantVideo || $this->ai->available('Image')) {
            $providers[] = $this->ai->image($full, $project->ratio, $disk->path($imgRel));
            $this->forget($scene['img'] ?? null);
            $scene['img'] = $imgRel;
        }

        if ($wantVideo) {
            $clipRel = $dir.'/scene-'.$scene['id'].'-'.Str::lower(Str::random(6)).'.mp4';
            $providers[] = $this->ai->video($prompt, $project->ratio, (int) ($scene['dur'] ?? 5), isset($scene['img']) ? $disk->path($scene['img']) : null, $disk->path($clipRel));
            $this->forget($scene['clip'] ?? null);
            $scene['clip'] = $clipRel;
        } else {
            $this->forget($scene['clip'] ?? null);
            unset($scene['clip']);
        }

        $scene['v'] = 'done';
        $scene['vp'] = 100;

        return ['scene' => $scene, 'provider' => implode(' + ', array_unique($providers))];
    }

    /** Store a user-uploaded image or video as the scene visual. */
    public function upload(Project $project, array $scene, UploadedFile $file): array
    {
        $dir = $this->dir($project);
        $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');
        $rel = $file->storeAs($dir, 'scene-'.$scene['id'].'-upload-'.Str::lower(Str::random(6)).'.'.($file->guessExtension() ?: ($isVideo ? 'mp4' : 'png')), 'public');

        if ($isVideo) {
            $this->forget($scene['clip'] ?? null);
            $scene['clip'] = $rel;
        } else {
            $this->forget($scene['img'] ?? null);
            $this->forget($scene['clip'] ?? null);
            unset($scene['clip']);
            $scene['img'] = $rel;
        }
        $scene['src'] = 'Upload';
        $scene['v'] = 'done';
        $scene['vp'] = 100;

        return $scene;
    }

    /** Voiceover for the scene narration; regenerated only when the text or voice changed. */
    public function voice(Project $project, array $scene, string $voice): array
    {
        $text = trim((string) ($scene['narration'] ?? ''));
        if ($text === '') {
            return ['scene' => $scene, 'provider' => null];
        }
        $sig = md5($voice.'|'.$text);
        if (! empty($scene['audio']) && ($scene['audioSig'] ?? '') === $sig && Storage::disk('public')->exists($scene['audio'])) {
            return ['scene' => $scene, 'provider' => null];
        }

        $rel = $this->dir($project).'/voice-'.$scene['id'].'-'.substr($sig, 0, 8).'.mp3';
        $provider = $this->ai->speech($text, $voice, Storage::disk('public')->path($rel));
        $this->forget($scene['audio'] ?? null);
        $scene['audio'] = $rel;
        $scene['audioSig'] = $sig;

        return ['scene' => $scene, 'provider' => $provider];
    }

    /** A short sample of a Studio voice (cached per voice). */
    public function preview(string $voice): string
    {
        $rel = 'voices/'.Str::slug($voice).'.mp3';
        $disk = Storage::disk('public');
        if (! $disk->exists($rel)) {
            $disk->makeDirectory('voices');
            $this->ai->speech("Hi, I'm {$voice}. This is how your video will sound with my voice.", $voice, $disk->path($rel));
            ActivityLog::record("Voice preview created for {$voice}", 'ai');
        }

        return $disk->url($rel);
    }

    private function forget(?string $rel): void
    {
        if ($rel && ! str_contains($rel, '-upload-')) {
            Storage::disk('public')->delete($rel);
        }
    }
}
