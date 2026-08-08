<?php

namespace App\Support;

class ApiId
{
    public static function dual(mixed $id): array
    {
        $value = (string) $id;

        return [
            'id' => $value,
            '_id' => $value,
        ];
    }
}
