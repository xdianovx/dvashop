<?php

namespace App\Filament\Support;

use App\Enums\AdminPermission;
use App\Filament\Pages\SitePagesPage;
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

abstract class SiteContentEditorPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.site-content.editor';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @return list<AdminPermission> */
    abstract protected static function viewPermissions(): array;

    /** @return list<AdminPermission> */
    abstract protected static function updatePermissions(): array;

    /** @return array<string, mixed> */
    abstract protected function loadState(SitePageContentAdminService $service): array;

    /** @param array<string, mixed> $data */
    abstract protected function persistState(SitePageContentAdminService $service, User $actor, array $data): void;

    abstract protected function successNotificationTitle(): string;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && collect(static::viewPermissions())
                ->every(fn (AdminPermission $permission): bool => $user->canPerformAdminAction($permission));
    }

    public function canUpdate(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && collect(static::updatePermissions())
                ->every(fn (AdminPermission $permission): bool => $user->canPerformAdminAction($permission));
    }

    public function mount(SitePageContentAdminService $service): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill($this->loadState($service));
    }

    public function save(SitePageContentAdminService $service): void
    {
        $actor = Filament::auth()->user();

        if (! $actor instanceof User || ! $this->canUpdate()) {
            throw new AuthorizationException('Недостаточно прав для сохранения содержимого страницы.');
        }

        try {
            $this->persistState($service, $actor, $this->form->getState());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [
                    str_starts_with($field, 'data.') ? $field : 'data.'.$field => $messages,
                ])
                ->all());
        }

        $this->form->fill($this->loadState($service));

        Notification::make()
            ->success()
            ->title($this->successNotificationTitle())
            ->send();
    }

    public function getSubheading(): ?string
    {
        return $this->canUpdate()
            ? 'Редактируются только предусмотренные макетом поля. Структура, изображения и системный порядок защищены.'
            : 'Режим просмотра: изменения и сохранение недоступны для вашей роли.';
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            SitePagesPage::getUrl() => 'Страницы сайта',
            $this->getTitle(),
        ];
    }
}
