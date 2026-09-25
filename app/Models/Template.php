<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $fillable = ['name', 'category', 'duration', 'ratio', 'scenes', 'uses', 'status', 'created_by', 'structure'];

    protected function casts(): array
    {
        return ['structure' => 'array'];
    }

    public function toClient(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'cat' => $this->category, 'dur' => $this->duration,
            'ratio' => $this->ratio, 'scenes' => $this->scenes, 'uses' => number_format($this->uses),
            'status' => $this->status, 'by' => $this->created_by,
        ];
    }
}
