<?php

namespace Database\Seeders;

use App\Models\AiProvider;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Template;
use Illuminate\Database\Seeder;

/** Base catalog every installation needs: plans, templates, the AI provider directory and CMS pages. */
class CatalogSeeder extends Seeder
{
    public const PROVIDERS = [
        // slug, name, mono, category, tier, driver, base url, default model, models, initial status
        ['or', 'OpenRouter', 'OR', 'Text', 'Free models available', 'openai', 'https://openrouter.ai/api/v1', 'meta-llama/llama-3.3-70b-instruct:free', ['meta-llama/llama-3.3-70b-instruct:free', 'deepseek/deepseek-chat-v3-0324:free', 'openai/gpt-4o-mini'], 'off'],
        ['gem', 'Google Gemini', 'GG', 'Text', 'Free tier', 'gemini', 'https://generativelanguage.googleapis.com', 'gemini-2.0-flash', ['gemini-2.0-flash', 'gemini-1.5-flash'], 'off'],
        ['groq', 'Groq', 'GQ', 'Text', 'Free tier', 'openai', 'https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile', ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant'], 'off'],
        ['cft', 'Cloudflare Workers AI', 'CF', 'Text', 'Free daily quota', 'cloudflare', 'https://api.cloudflare.com/client/v4/accounts/YOUR_ACCOUNT_ID', '@cf/meta/llama-3.1-8b-instruct', ['@cf/meta/llama-3.1-8b-instruct'], 'off'],
        ['hft', 'Hugging Face', 'HF', 'Text', 'Free inference', 'huggingface', 'https://router.huggingface.co/v1', 'meta-llama/Llama-3.1-8B-Instruct', ['meta-llama/Llama-3.1-8B-Instruct', 'mistralai/Mistral-7B-Instruct-v0.3'], 'off'],
        ['custom', 'Custom OpenAI-compatible', '{ }', 'Text', 'Any compatible endpoint', 'openai', 'http://localhost:8000/v1', 'qwen2.5-32b-instruct', ['qwen2.5-32b-instruct'], 'off'],
        ['cf', 'Cloudflare Workers AI', 'CF', 'Image', 'Free daily quota', 'cloudflare', 'https://api.cloudflare.com/client/v4/accounts/YOUR_ACCOUNT_ID', 'flux-1-schnell', ['flux-1-schnell', 'stable-diffusion-xl-lightning'], 'off'],
        ['hf', 'Hugging Face', 'HF', 'Image', 'Free inference', 'huggingface', 'https://router.huggingface.co/hf-inference', 'stable-diffusion-xl', ['stable-diffusion-xl'], 'off'],
        ['stab', 'Stability AI', 'SA', 'Image', 'Paid', 'stability', 'https://api.stability.ai', 'stable-image-core', ['stable-image-core'], 'off'],
        ['repi', 'Replicate', 'RP', 'Image', 'Pay as you go', 'replicate', 'https://api.replicate.com/v1', 'flux-1.1-pro', ['flux-1.1-pro'], 'off'],
        ['fal', 'Fal.ai', 'FA', 'Video', 'Pay as you go', 'fal', 'https://fal.run', 'ltx-video', ['ltx-video', 'wan-2.1-t2v'], 'off'],
        ['repv', 'Replicate', 'RP', 'Video', 'Pay as you go', 'replicate', 'https://api.replicate.com/v1', 'hunyuan-video', ['hunyuan-video'], 'off'],
        ['run', 'Runway', 'RW', 'Video', 'Paid', 'runway', 'https://api.dev.runwayml.com/v1', 'gen3a_turbo', ['gen3a_turbo', 'gen4_turbo'], 'off'],
        ['luma', 'Luma', 'LM', 'Video', 'Paid', 'luma', 'https://api.lumalabs.ai/dream-machine/v1', 'ray-2', ['ray-2'], 'off'],
        ['edge', 'Edge TTS', 'ET', 'Voice', 'Free · no key needed', 'none', null, 'en-US-Neural', ['en-US-Neural'], 'connected'],
        ['piper', 'Piper', 'PI', 'Voice', 'Self-hosted', 'local', null, '/opt/piper/en_US-lessac-medium.onnx', ['/opt/piper/en_US-lessac-medium.onnx'], 'off'],
        ['el', 'ElevenLabs', 'EL', 'Voice', 'Paid · voice cloning', 'elevenlabs', 'https://api.elevenlabs.io/v1', 'eleven_multilingual_v2', ['eleven_multilingual_v2'], 'off'],
        ['oat', 'OpenAI TTS', 'OA', 'Voice', 'Paid', 'openai', 'https://api.openai.com/v1', 'tts-1', ['tts-1', 'tts-1-hd'], 'off'],
        ['whisper', 'Groq Whisper', 'GW', 'Speech', 'Speech-to-text · free tier', 'openai', 'https://api.groq.com/openai/v1', 'whisper-large-v3', ['whisper-large-v3'], 'off'],
        ['dg', 'Deepgram', 'DG', 'Speech', 'Speech-to-text · paid', 'generic', 'https://api.deepgram.com/v1/projects', 'nova-3', ['nova-3'], 'off'],
        ['suno', 'Music API (custom)', 'MU', 'Audio', 'Background music · paid', 'generic', null, 'music-v1', ['music-v1'], 'off'],
    ];

    public function run(): void
    {
        $plans = [
            ['Free', 'free', 'Try it with free AI providers.', 0, 50, '3', 1, false, false, ['Free AI models only', '720p export', 'Watermark on videos']],
            ['Starter', 'starter', 'For creators posting weekly.', 19, 1500, '30', 10, false, false, ['Paid AI models', 'Remove watermark', '1080p export', 'Brand kit']],
            ['Professional', 'professional', 'For teams publishing daily.', 49, 5000, 'Unlimited', 50, true, true, ['Paid AI models', 'Remove watermark', 'Custom voices', 'API access', 'Priority rendering']],
            ['Agency', 'agency', 'For agencies serving clients.', 99, 15000, 'Unlimited', 200, true, false, ['Paid AI models', 'Remove watermark', 'Custom voices', 'API access', 'Priority rendering', 'Team seats', 'White-label exports']],
        ];
        foreach ($plans as $i => $p) {
            Plan::updateOrCreate(['slug' => $p[1]], [
                'name' => $p[0], 'description' => $p[2], 'price' => $p[3], 'credits' => $p[4], 'videos_label' => $p[5],
                'storage_gb' => $p[6], 'api_access' => $p[7], 'popular' => $p[8], 'features' => $p[9], 'sort' => $i,
            ]);
        }

        $templates = [['Hook · Story · Offer', 'TikTok', '0:30', '9:16', 6], ['Product Spotlight', 'Product Ads', '0:20', '9:16', 4], ['Listing Walkthrough', 'Real Estate', '1:00', '16:9', 8], ['Feature Launch', 'SaaS Ads', '0:45', '16:9', 6], ['Top 5 Countdown', 'Faceless Content', '0:58', '9:16', 7], ['Mini Lesson', 'Education', '1:30', '16:9', 9], ['UGC Testimonial', 'Instagram Reels', '0:25', '9:16', 5], ['Explainer in 3 Acts', 'Explainer', '1:15', '16:9', 6], ['Flash Sale', 'Ecommerce', '0:15', '1:1', 3], ['Channel Intro', 'YouTube', '0:40', '16:9', 5], ['Quick Tip', 'YouTube Shorts', '0:30', '9:16', 4], ['Before / After', 'Ecommerce', '0:20', '4:5', 4], ['Market Update', 'Real Estate', '0:50', '9:16', 6], ['Story Narration', 'Faceless Content', '1:00', '9:16', 8], ['Pain · Solution · Proof', 'SaaS Ads', '0:35', '9:16', 5], ['Course Trailer', 'Education', '0:45', '16:9', 6]];
        foreach ($templates as $t) {
            Template::firstOrCreate(['name' => $t[0]], ['category' => $t[1], 'duration' => $t[2], 'ratio' => $t[3], 'scenes' => $t[4], 'status' => 'Published']);
        }

        foreach (self::PROVIDERS as $i => $p) {
            AiProvider::firstOrCreate(['slug' => $p[0]], [
                'name' => $p[1], 'mono' => $p[2], 'category' => $p[3], 'tier' => $p[4], 'driver' => $p[5], 'base_url' => $p[6],
                'model' => $p[7], 'models' => $p[8], 'status' => $p[9], 'priority' => 10 + $i,
            ]);
        }

        if (Setting::get('pages') === null) {
            Setting::put('pages', [
                ['name' => 'Home', 'slug' => '/', 'status' => 'Published', 'title' => 'Create professional AI videos with your own AI providers', 'desc' => 'Idea to script to finished video in minutes. No mandatory platform subscription.', 'body' => ''],
                ['name' => 'About', 'slug' => '/about', 'status' => 'Published', 'title' => 'About us', 'desc' => 'We help businesses publish more video with less effort.', 'body' => ''],
                ['name' => 'Pricing', 'slug' => '/pricing', 'status' => 'Published', 'title' => 'Pricing', 'desc' => 'Simple plans that scale with your content.', 'body' => ''],
                ['name' => 'Terms', 'slug' => '/legal/terms', 'status' => 'Published', 'title' => 'Terms of Service', 'desc' => 'These terms govern your use of the service.', 'body' => ''],
                ['name' => 'Privacy', 'slug' => '/legal/privacy', 'status' => 'Draft', 'title' => 'Privacy Policy', 'desc' => 'How we collect, use and protect your data.', 'body' => ''],
                ['name' => 'Contact', 'slug' => '/contact', 'status' => 'Published', 'title' => 'Contact us', 'desc' => 'Questions about plans, billing or enterprise? We reply within one business day.', 'body' => ''],
            ]);
        }
    }
}
