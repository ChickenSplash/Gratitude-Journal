<?php

namespace App\Models;

use Database\Factories\EntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['public_id', 'entry_date'])]
class Entry extends Model
{
    /** @use HasFactory<EntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'entry_date' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EntryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EntryItem::class)->orderBy('position');
    }

    /**
     * Newest first, with same-day entries falling back to insertion order.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('entry_date')->orderByDesc('id');
    }

    /**
     * "Today", "Yesterday", "5 Aug", or "5 Aug 2024" once the year stops being
     * obvious — the same ladder the original front end used.
     */
    protected function displayDate(): Attribute
    {
        return Attribute::get(function (): string {
            $date = $this->entry_date;

            return match (true) {
                $date->isToday() => 'Today',
                $date->isYesterday() => 'Yesterday',
                $date->isCurrentYear() => $date->format('j M'),
                default => $date->format('j M Y'),
            };
        });
    }

    /**
     * The shape the browser and an export file both see.
     *
     * @return array{id: string, date: string, items: list<string>}
     */
    public function toJournalArray(): array
    {
        return [
            'id' => $this->public_id,
            'date' => $this->entry_date->toIso8601ZuluString('millisecond'),
            'items' => $this->items->pluck('body')->all(),
        ];
    }
}
