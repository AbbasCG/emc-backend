<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class IntegrationSetting extends Model
{
    protected $fillable = ['key', 'provider', 'config', 'status', 'created_by'];

    /**
     * Store config as encrypted JSON — never expose raw secrets.
     */
    public function setConfigAttribute(array|string $value): void
    {
        $json = is_array($value) ? json_encode($value) : $value;
        $this->attributes['config'] = Crypt::encryptString($json);
    }

    public function getConfigAttribute(string $value): array
    {
        try {
            return json_decode(Crypt::decryptString($value), true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return config without sensitive fields for API responses.
     */
    public function safeConfig(): array
    {
        $config = $this->config;
        $sensitiveKeys = ['token', 'secret', 'key', 'password', 'api_key', 'client_secret', 'access_token'];

        foreach ($sensitiveKeys as $field) {
            if (isset($config[$field])) {
                $config[$field] = '***';
            }
        }

        return $config;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
