<?php

namespace Tests\Models;

class Post extends Model
{
    protected $casts = [
        'options' => 'json'
    ];

    public function recommendations()
    {
        return $this->belongsToJson(self::class, 'options->recommendation_ids');
    }

    public function recommendations2()
    {
        return $this->belongsToJson(self::class, 'options->recommendations[]->post_id');
    }

    public function recommenders()
    {
        return $this->hasManyJson(self::class, 'options->recommendation_ids');
    }

    public function recommenders2()
    {
        return $this->hasManyJson(self::class, 'options->recommendations[]->post_id');
    }
}
