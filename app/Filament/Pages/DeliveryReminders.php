<?php

namespace App\Filament\Pages;

use App\Models\DeliveryConfirmationSend;
use App\Models\User;
use App\Services\DeliveryConfirmation\DeliveryReminderDashboardService;
use App\Support\Authorization\ShieldRoleCatalog;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Settings and sent-mail overview for the delivery-reminder agent.
 *
 * Secrets stay in .env. This page only shows non-secret configuration and local
 * send / reply outcomes so a planner can see what the agent has done.
 */
class DeliveryReminders extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'Logistics';

    protected static ?string $navigationLabel = 'Delivery reminders';

    protected static ?string $title = 'Delivery reminders';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.delivery-reminders';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $permission = app(ShieldRoleCatalog::class)->pagePermissionKey(static::class);

        return $user instanceof User
            && $permission !== null
            && $user->can($permission);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->recentSendsQuery())
            ->defaultSort('sent_at', 'desc')
            ->paginated([25, 50])
            ->columns([
                TextColumn::make('entity')
                    ->label('Entity')
                    ->badge()
                    ->sortable(),
                TextColumn::make('reference_nbr')
                    ->label('Order number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('stage')
                    ->label('Stage')
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state)
                    ->sortable(),
                TextColumn::make('sent_at')
                    ->label('Sent at')
                    ->dateTime('d M Y, g:i A')
                    ->sortable(),
                TextColumn::make('sender_mailbox')
                    ->label('From')
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('recipient_email')
                    ->label('Recipient')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('reply_outcome')
                    ->label('Reply')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'processed' => 'success',
                        'unresolved' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->emptyStateHeading('No delivery reminder emails sent yet')
            ->emptyStateDescription('Emails appear here after delivery-reminders:send runs with the agent enabled.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $dashboard = app(DeliveryReminderDashboardService::class);

        return [
            'settings' => $dashboard->settings(),
            'totals' => $dashboard->totals(),
            'entitySummaries' => $dashboard->entitySummaries(),
        ];
    }

    private function recentSendsQuery(): Builder
    {
        return DeliveryConfirmationSend::query()
            ->where('status', 'sent');
    }
}
