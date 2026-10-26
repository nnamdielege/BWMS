<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Records created/updated/deleted events for the model in audit_logs.
 * Models may define `protected array $auditExclude` to omit sensitive or noisy fields.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            Audit::record('created', $model, null, $model->auditFilter($model->getAttributes()));
        });

        static::updated(function (Model $model) {
            $changes = $model->auditFilter($model->getChanges());
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);
            Audit::record('updated', $model, $model->auditFilter($old), $changes);
        });

        static::deleted(function (Model $model) {
            Audit::record('deleted', $model, $model->auditFilter($model->getAttributes()));
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function auditFilter(array $values): array
    {
        $exclude = property_exists($this, 'auditExclude') ? $this->auditExclude : [];

        return array_diff_key($values, array_flip($exclude));
    }
}
