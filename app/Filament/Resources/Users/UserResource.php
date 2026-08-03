<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\UserAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Система';
    }

    public static function getNavigationLabel(): string
    {
        return 'Пользователи';
    }

    public static function getModelLabel(): string
    {
        return 'пользователь';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Пользователи';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Имя')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('role')
                    ->label('Роль')
                    ->options(UserRole::options())
                    ->required(),
                Toggle::make('is_active')
                    ->label('Активен')
                    ->default(true),
                TextInput::make('password')
                    ->label(fn (string $operation): string => $operation === 'create' ? 'Пароль' : 'Новый пароль')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->rule(Password::defaults())
                    ->confirmed()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->formatStateUsing(fn (): null => null),
                TextInput::make('password_confirmation')
                    ->label('Подтверждение пароля')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required(fn (string $operation, Get $get): bool => $operation === 'create' || filled($get('password')))
                    ->dehydrated(fn (Get $get): bool => filled($get('password')))
                    ->formatStateUsing(fn (): null => null),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Пользователи не найдены')
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->formatStateUsing(fn (UserRole|string|null $state): string => $state instanceof UserRole
                        ? $state->label()
                        : (UserRole::tryFrom((string) $state)?->label() ?? '—'))
                    ->sortable(),
                TextColumn::make('admin_status')
                    ->label('Статус')
                    ->state(fn (User $record): string => $record->adminStatusLabel())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Активен' => 'success',
                        'Заблокирован' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Обновлён')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Роль')
                    ->options(UserRole::options()),
                SelectFilter::make('admin_status')
                    ->label('Статус')
                    ->options([
                        'active' => 'Активен',
                        'inactive' => 'Отключён',
                        'blocked' => 'Заблокирован',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->where('is_active', true)->whereNull('blocked_at'),
                            'inactive' => $query->where('is_active', false)->whereNull('blocked_at'),
                            'blocked' => $query->whereNotNull('blocked_at'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('block')
                    ->label('Заблокировать')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Заблокировать пользователя?')
                    ->visible(fn (User $record): bool => $record->blocked_at === null)
                    ->action(fn (User $record, UserAdminService $service) => self::runStatusAction(
                        fn (): User => $service->block(self::actor(), $record),
                        'Пользователь заблокирован',
                    )),
                Action::make('unblock')
                    ->label('Разблокировать')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->visible(fn (User $record): bool => $record->blocked_at !== null)
                    ->action(fn (User $record, UserAdminService $service) => self::runStatusAction(
                        fn (): User => $service->unblock(self::actor(), $record),
                        'Пользователь разблокирован',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    private static function actor(): User
    {
        $actor = Filament::auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private static function runStatusAction(callable $action, string $successMessage): void
    {
        try {
            $action();

            Notification::make()
                ->success()
                ->title($successMessage)
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title((string) (collect($exception->errors())->flatten()->first() ?: 'Изменение пользователя запрещено.'))
                ->send();
        }
    }
}
