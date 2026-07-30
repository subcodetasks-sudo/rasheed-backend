<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'accepted'             => 'يجب قبول حقل :attribute.',
    'accepted_if'          => 'يجب قبول حقل :attribute عندما يكون :other هو :value.',
    'active_url'           => 'حقل :attribute يجب أن يكون رابطًا صالحًا.',
    'after'                => 'حقل :attribute يجب أن يكون تاريخًا بعد :date.',
    'after_or_equal'       => 'حقل :attribute يجب أن يكون تاريخًا يساوي أو بعد :date.',
    'alpha'                => 'حقل :attribute يجب أن يحتوي على حروف فقط.',
    'alpha_dash'           => 'حقل :attribute يجب أن يحتوي على حروف وأرقام وشرطة وشرط سفلية فقط.',
    'alpha_num'            => 'حقل :attribute يجب أن يحتوي على حروف وأرقام فقط.',
    'any_of'               => 'حقل :attribute غير صالح.',
    'array'                => 'حقل :attribute يجب أن يكون مصفوفة.',
    'ascii'                => 'حقل :attribute يجب أن يحتوي على أحرف ورموز أحادية البايت فقط.',
    'before'               => 'حقل :attribute يجب أن يكون تاريخًا قبل :date.',
    'before_or_equal'      => 'حقل :attribute يجب أن يكون تاريخًا يساوي أو قبل :date.',
    'between'              => [
        'array'   => 'حقل :attribute يجب أن يحتوي بين :min و :max عناصر.',
        'file'    => 'حقل :attribute يجب أن يكون بين :min و :max كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون بين :min و :max.',
        'string'  => 'حقل :attribute يجب أن يكون بين :min و :max حرف.',
    ],
    'boolean'              => 'حقل :attribute يجب أن يكون true أو false.',
    'confirmed'            => 'تأكيد حقل :attribute غير مطابق.',
    'current_password'     => 'كلمة المرور غير صحيحة.',
    'date'                 => 'حقل :attribute يجب أن يكون تاريخًا صالحًا.',
    'date_equals'          => 'حقل :attribute يجب أن يكون تاريخًا يساوي :date.',
    'date_format'          => 'حقل :attribute لا يطابق الصيغة :format.',
    'declined'             => 'حقل :attribute يجب رفضه.',
    'declined_if'          => 'حقل :attribute يجب رفضه عندما يكون :other هو :value.',
    'different'            => 'حقل :attribute و :other يجب أن يكونا مختلفين.',
    'digits'               => 'حقل :attribute يجب أن يكون :digits أرقام.',
    'digits_between'       => 'حقل :attribute يجب أن يكون بين :min و :max أرقام.',
    'dimensions'           => 'حقل :attribute يحتوي على أبعاد صورة غير صالحة.',
    'distinct'             => 'حقل :attribute يحتوي على قيمة مكررة.',
    'email'                => 'حقل :attribute يجب أن يكون بريدًا إلكترونيًا صالحًا.',
    'ends_with'            => 'حقل :attribute يجب أن ينتهي بأحد القيم التالية: :values.',
    'exists'               => 'القيمة المحددة في :attribute غير صالحة.',
    'file'                 => 'حقل :attribute يجب أن يكون ملفًا.',
    'filled'               => 'حقل :attribute يجب أن يحتوي على قيمة.',
    'gt'                   => [
        'array'   => 'حقل :attribute يجب أن يحتوي على أكثر من :value عناصر.',
        'file'    => 'حقل :attribute يجب أن يكون أكبر من :value كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون أكبر من :value.',
        'string'  => 'حقل :attribute يجب أن يكون أكبر من :value حرف.',
    ],
    'gte'                  => [
        'array'   => 'حقل :attribute يجب أن يحتوي على :value عناصر أو أكثر.',
        'file'    => 'حقل :attribute يجب أن يكون أكبر من أو يساوي :value كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون أكبر من أو يساوي :value.',
        'string'  => 'حقل :attribute يجب أن يكون أكبر من أو يساوي :value حرف.',
    ],
    'image'                => 'حقل :attribute يجب أن يكون صورة.',
    'in'                   => 'القيمة المحددة في :attribute غير صالحة.',
    'in_array'             => 'حقل :attribute غير موجود في :other.',
    'integer'              => 'حقل :attribute يجب أن يكون عددًا صحيحًا.',
    'ip'                   => 'حقل :attribute يجب أن يكون عنوان IP صالحًا.',
    'ipv4'                 => 'حقل :attribute يجب أن يكون عنوان IPv4 صالحًا.',
    'ipv6'                 => 'حقل :attribute يجب أن يكون عنوان IPv6 صالحًا.',
    'json'                 => 'حقل :attribute يجب أن يكون نص JSON صالحًا.',
    'lt'                   => [
        'array'   => 'حقل :attribute يجب أن يحتوي على أقل من :value عناصر.',
        'file'    => 'حقل :attribute يجب أن يكون أقل من :value كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون أقل من :value.',
        'string'  => 'حقل :attribute يجب أن يكون أقل من :value حرف.',
    ],
    'lte'                  => [
        'array'   => 'حقل :attribute يجب ألا يحتوي على أكثر من :value عناصر.',
        'file'    => 'حقل :attribute يجب أن يكون أقل من أو يساوي :value كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون أقل من أو يساوي :value.',
        'string'  => 'حقل :attribute يجب أن يكون أقل من أو يساوي :value حرف.',
    ],
    'max'                  => [
        'array'   => 'حقل :attribute يجب ألا يحتوي على أكثر من :max عناصر.',
        'file'    => 'حقل :attribute يجب ألا يزيد عن :max كيلوبايت.',
        'numeric' => 'حقل :attribute يجب ألا يكون أكبر من :max.',
        'string'  => 'حقل :attribute يجب ألا يزيد عن :max حرف.',
    ],
    'min'                  => [
        'array'   => 'حقل :attribute يجب أن يحتوي على الأقل :min عناصر.',
        'file'    => 'حقل :attribute يجب أن يكون على الأقل :min كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون على الأقل :min.',
        'string'  => 'حقل :attribute يجب أن يكون على الأقل :min حرف.',
    ],
    'not_in'               => 'القيمة المحددة في :attribute غير صالحة.',
    'not_regex'            => 'صيغة حقل :attribute غير صالحة.',
    'numeric'              => 'حقل :attribute يجب أن يكون رقمًا.',
    'regex'                => 'صيغة حقل :attribute غير صالحة.',
    'required'             => 'حقل :attribute مطلوب.',
    'required_if'          => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_unless'      => 'حقل :attribute مطلوب إلا إذا كان :other ضمن :values.',
    'required_with'        => 'حقل :attribute مطلوب عندما يكون :values موجودًا.',
    'required_with_all'    => 'حقل :attribute مطلوب عندما تكون جميع :values موجودة.',
    'required_without'     => 'حقل :attribute مطلوب عندما لا يكون :values موجودًا.',
    'required_without_all' => 'حقل :attribute مطلوب عندما لا تكون أي من :values موجودة.',
    'same'                 => 'حقل :attribute يجب أن يطابق :other.',
    'size'                 => [
        'array'   => 'حقل :attribute يجب أن يحتوي على :size عناصر.',
        'file'    => 'حقل :attribute يجب أن يكون :size كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون :size.',
        'string'  => 'حقل :attribute يجب أن يكون :size حرف.',
    ],
    'string'               => 'حقل :attribute يجب أن يكون نصًا.',
    'timezone'             => 'حقل :attribute يجب أن يكون منطقة زمنية صالحة.',
    'unique'               => 'حقل :attribute تم استخدامه مسبقًا.',
    'uploaded'             => 'فشل رفع حقل :attribute.',
    'url'                  => 'صيغة حقل :attribute غير صالحة.',
    'uuid'                 => 'حقل :attribute يجب أن يكون UUID صالحًا.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'رسالة مخصصة',
        ],
        'avatar' => [
            'image' => 'يجب أن تكون الصورة الشخصية صورة.',
            'mimes' => 'يجب أن تكون الصورة الشخصية من نوع: jpeg، png، jpg، gif، webp.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'type' => 'النوع',
        'expires_at' => 'تاريخ انتهاء الصلاحية',
        'user_id' => 'المستخدم',
        'token' => 'رمز الوصول',
        'is_revoked' => 'ملغى',
        'user_id' => 'المستخدم',
        'provider' => 'مزود الحساب',
        'provider_id' => 'معرّف المزود',
        'uuid' => 'المعرّف الفريد',
        'phone' => 'رقم الهاتف',
        'status' => 'حالة الحساب',
        'last_login_at' => 'آخر تسجيل دخول',
        'locale' => 'اللغة',
        'timezone' => 'المنطقة الزمنية',
        'preferences' => 'التفضيلات',
        'avatar' => 'الصورة الشخصية',
    ],

];