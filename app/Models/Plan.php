<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'price', 'credits', 'videos_label', 'storage_gb', 'api_access', 'popular', 'features', 'sort'];

    protected function casts(): array
    {
        return ['features' => 'array', 'api_access' => 'boolean', 'popular' => 'boolean', 'price' => 'float', 'credits' => 'integer'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function toClient(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'desc' => $this->description, 'price' => $this->price,
            'credits' => $this->credits, 'videos' => $this->videos_label, 'storage' => $this->storage_gb.' GB',
            'api' => $this->api_access, 'popular' => $this->popular, 'features' => $this->features ?? [],
            'subs' => $this->users_count ?? $this->users()->count(),
        ];
    }
}
