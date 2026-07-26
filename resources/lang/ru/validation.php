<?php

return [
    'accepted' => 'Поле :attribute должно быть принято.',
    'boolean' => 'Поле :attribute должно иметь значение «да» или «нет».',
    'file' => 'Поле :attribute должно содержать файл.',
    'in' => 'Выбранное значение для поля :attribute некорректно.',
    'integer' => 'Поле :attribute должно быть целым числом.',
    'max' => [
        'file' => 'Размер файла в поле :attribute не должен превышать :max КБ.',
        'numeric' => 'Значение поля :attribute не должно превышать :max.',
        'string' => 'Количество символов в поле :attribute не должно превышать :max.',
    ],
    'mimes' => 'Файл в поле :attribute должен иметь тип: :values.',
    'required' => 'Поле :attribute обязательно для заполнения.',
    'string' => 'Поле :attribute должно быть строкой.',

    'attributes' => [
        'file' => 'файл импорта',
        'type' => 'источник импорта',
        'chunkSize' => 'размер чанка',
        'startAfterUpload' => 'запуск после загрузки',
    ],
];
