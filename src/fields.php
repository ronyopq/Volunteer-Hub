<?php
declare(strict_types=1);

// Labels and options follow the supplied PRAAN registration form (three-page print layout).
function form_sections(): array {
    return [
        'header' => ['title' => 'নিবন্ধন পরিচিতি', 'fields' => [
            'form_no' => ['ফর্ম নং / Form No.', 'text'],
            'project' => ['প্রকল্প/কর্মসূচি / Project', 'text'],
            'registration_date' => ['নিবন্ধনের তারিখ', 'date'],
        ]],
        'A' => ['title' => 'A. ব্যক্তিগত ও যোগাযোগের তথ্য', 'fields' => [
            'name_en' => ['নাম (ইংরেজি BLOCK LETTER)', 'text'],
            'name_bn' => ['নাম (বাংলায়)', 'text'],
            'gender' => ['লিঙ্গ', 'select', ['নারী','পুরুষ','অন্যান্য/বলতে অনিচ্ছুক']],
            'dob' => ['জন্মতারিখ', 'date'],
            'age_reported' => ['ফর্মে দেওয়া বয়স / Age (Years)', 'text'],
            'blood_group' => ['রক্তের গ্রুপ', 'select', ['A+','A-','B+','B-','AB+','AB-','O+','O-','জানা নেই']],
            'occupation' => ['পেশা', 'text'],
            'education' => ['সর্বশেষ শিক্ষাগত যোগ্যতা', 'text'],
            'id_document_type' => ['পরিচয়পত্রের ধরন', 'select', ['NID','Birth Certificate','অনির্ধারিত']],
            'id_number' => ['জন্মনিবন্ধন / NID নং', 'text'],
            'phone' => ['মোবাইল নং', 'tel'],
            'alternate_phone' => ['বিকল্প মোবাইল নং', 'tel'],
            'whatsapp' => ['হোয়াটসঅ্যাপ নং', 'tel'],
            'email' => ['ইমেইল', 'email'],
            'smartphone' => ['স্মার্টফোন ও ইন্টারনেট ব্যবহার', 'select', ['হ্যাঁ','না']],
            'mfs' => ['মোবাইল ফাইন্যান্সিয়াল সার্ভিস', 'multi', ['বিকাশ','নগদ','রকেট','অন্যান্য']],
            'mfs_ownership' => ['অ্যাকাউন্টের মালিকানা', 'select', ['নিজ','অন্য']],
            'mfs_relation' => ['অন্যের হলে সম্পর্ক', 'text'],
            'emergency_name' => ['জরুরি যোগাযোগের নাম', 'text'],
            'emergency_relation' => ['জরুরি যোগাযোগের সম্পর্ক', 'text'],
            'emergency_phone' => ['জরুরি যোগাযোগের মোবাইল', 'tel'],
            'mother_name' => ['মাতার নাম', 'text'],
            'mother_phone' => ['মাতার মোবাইল', 'tel'],
            'father_name' => ['পিতার নাম', 'text'],
            'father_phone' => ['পিতার মোবাইল', 'tel'],
        ]],
        'B' => ['title' => 'B. ঠিকানা', 'fields' => [
            'present_holding' => ['বর্তমান: বাড়ি/হোল্ডিং', 'text'],
            'present_village' => ['বর্তমান: গ্রাম/মহল্লা', 'text'],
            'present_ward' => ['বর্তমান: ওয়ার্ড', 'text'],
            'present_union' => ['বর্তমান: ইউনিয়ন/পৌরসভা', 'text'],
            'present_upazila' => ['বর্তমান: উপজেলা/থানা', 'text'],
            'present_district' => ['বর্তমান: জেলা', 'text'],
            'permanent_holding' => ['স্থায়ী: বাড়ি/হোল্ডিং', 'text'],
            'permanent_village' => ['স্থায়ী: গ্রাম/মহল্লা', 'text'],
            'permanent_ward' => ['স্থায়ী: ওয়ার্ড', 'text'],
            'permanent_union' => ['স্থায়ী: ইউনিয়ন/পৌরসভা', 'text'],
            'permanent_upazila' => ['স্থায়ী: উপজেলা/থানা', 'text'],
            'permanent_district' => ['স্থায়ী: জেলা', 'text'],
        ]],
        'C' => ['title' => 'C. স্বেচ্ছাসেবী আগ্রহ, দক্ষতা ও প্রাপ্যতা', 'fields' => [
            'interests' => ['যে ধরনের কাজে আগ্রহী', 'multi', ['জরিপ/ডাটা সংগ্রহ','ত্রাণ বিতরণ','দুর্যোগ প্রস্তুতি ও সাড়াদান','কমিউনিটি মোবিলাইজেশন','সচেতনতা/ক্যাম্পেইন','ইভেন্ট/লজিস্টিক সাপোর্ট','প্রাথমিক চিকিৎসা','মিডিয়া/ডকুমেন্টেশন','শিশু/কিশোর কার্যক্রম','অন্যান্য']],
            'interests_other' => ['অন্যান্য আগ্রহ', 'text'],
            'availability' => ['সাপ্তাহিক প্রাপ্যতা', 'multi', ['কর্মদিবস','সাপ্তাহিক ছুটি','উভয়']],
            'time_available' => ['সময়', 'multi', ['সকাল','বিকাল','সন্ধ্যা','জরুরি ডাক']],
            'travel_scope' => ['ভ্রমণ/মাঠে কাজের প্রস্তুতি', 'multi', ['নিজ ইউনিয়ন','উপজেলা','জেলা','দেশের অন্যত্র']],
            'emergency_duty' => ['রাত/জরুরি সময়ে দায়িত্ব', 'select', ['সম্ভব','সীমিত','সম্ভব নয়']],
            'languages' => ['ভাষা দক্ষতা', 'text'],
            'special_skills' => ['বিশেষ দক্ষতা', 'text'],
            'digital_skills' => ['ডিজিটাল/প্রযুক্তিগত দক্ষতা', 'multi', ['WhatsApp','Google Forms','KoBo/ODK','Excel/Sheets','GPS/Maps','ছবি/ভিডিও','Social Media','অন্যান্য']],
            'digital_skills_other' => ['অন্যান্য প্রযুক্তিগত দক্ষতা', 'text'],
        ]],
        'D' => ['title' => 'D. অভিজ্ঞতা ও প্রশিক্ষণ', 'fields' => [
            'experience_summary' => ['স্বেচ্ছাসেবী কাজের সংক্ষিপ্ত অভিজ্ঞতা', 'textarea'],
        ]],
        'E' => ['title' => 'E. নিরাপদ অংশগ্রহণ ও সহায়তার প্রয়োজন', 'fields' => [
            'support_needs' => ['নিরাপদে দায়িত্ব পালনে বিশেষ সহায়তা প্রয়োজন? (ঐচ্ছিক)', 'textarea'],
            'first_aid' => ['জরুরি পরিস্থিতিতে প্রাথমিক চিকিৎসা জানা', 'select', ['হ্যাঁ','না']],
            'swimming' => ['সাঁতার জানা', 'select', ['হ্যাঁ','না']],
            'transport' => ['নিজস্ব পরিবহন/যাতায়াত সুবিধা', 'multi', ['সাইকেল','মোটরবাইক','অন্যান্য','নেই']],
            'driving_license' => ['ড্রাইভিং লাইসেন্স ধরণ/নং', 'text'],
        ]],
        'F' => ['title' => 'F. ঘোষণা, আচরণবিধি ও তথ্য ব্যবহারের সম্মতি', 'fields' => [
            'consents' => ['সম্মতি ও আচরণবিধি', 'multi', [
                'code' => 'সংস্থার আচরণবিধি, নিরাপত্তা নির্দেশনা ও দায়িত্ব বণ্টন মেনে চলব।',
                'safeguarding' => 'শিশু সুরক্ষা, যৌন শোষণ ও নির্যাতন প্রতিরোধ (Safeguarding/PSEA) এবং বৈষম্যহীন আচরণ বজায় রাখব।',
                'confidentiality' => 'দায়িত্ব পালনে প্রাপ্ত ব্যক্তিগত/গোপনীয় তথ্য অনুমতি ছাড়া প্রকাশ করব না।',
                'data' => 'নিবন্ধন, যোগাযোগ, দায়িত্ব বণ্টন, ভাতা/ব্যয় নিষ্পত্তি ও প্রয়োজনীয় রিপোর্টিংয়ের জন্য আমার তথ্য ব্যবহার করতে সম্মত।',
                'emergency' => 'জরুরি অবস্থায় সংস্থা আমার জরুরি যোগাযোগ ব্যক্তির সঙ্গে যোগাযোগ করতে পারবে।',
                'media' => 'সংস্থার কার্যক্রমে অংশগ্রহণের সময় আমার ছবি, ভিডিও বা অডিও ধারণ করা হলে, সংস্থার প্রচার, প্রকাশনা, প্রতিবেদন, সামাজিক যোগাযোগমাধ্যম, ওয়েবসাইট, প্রিন্ট ও ডিজিটাল মিডিয়াসহ প্রয়োজনীয় যোগাযোগমাধ্যমে তা ব্যবহার, প্রকাশ ও প্রচারের জন্য আমি স্বেচ্ছায় অনুমতি প্রদান করছি (ঐচ্ছিক)।',
            ]],
            'volunteer_sign_name' => ['স্বেচ্ছাসেবকের স্বাক্ষরকারী নাম', 'text'],
            'volunteer_sign_date' => ['স্বাক্ষরের তারিখ', 'date'],
            'collector_sign_name' => ['তথ্য সংগ্রহকারীর নাম', 'text'],
            'collector_sign_date' => ['তথ্য সংগ্রহকারীর স্বাক্ষরের তারিখ', 'date'],
        ]],
        'G' => ['title' => 'G. সংযুক্তি', 'fields' => [
            'attachments' => ['সংযুক্তি', 'multi', ['জন্মনিবন্ধন/NID ফটোকপি','শিক্ষাগত যোগ্যতার প্রমাণ','অভিজ্ঞতা/প্রশিক্ষণ সনদ','১ কপি পাসপোর্ট সাইজ ছবি','অন্যান্য']],
            'attachments_other' => ['অন্যান্য সংযুক্তি', 'text'],
            'google_drive_link' => ['সংযুক্ত কাগজপত্রের Google Drive লিংক (ঐচ্ছিক)', 'text'],
        ]],
        'H' => ['title' => 'H. শুধু অফিস ব্যবহারের জন্য', 'admin_only' => true, 'fields' => [
            'document_check' => ['ডকুমেন্ট যাচাই', 'select', ['সম্পূর্ণ','অসম্পূর্ণ']],
            'reference_check' => ['রেফারেন্স যাচাই', 'select', ['হয়েছে','প্রয়োজন নেই','বাকি']],
            'orientation_date' => ['ওরিয়েন্টেশন তারিখ', 'date'],
            'category' => ['স্বেচ্ছাসেবক ক্যাটাগরি', 'select', ['সাধারণ','জরুরি সাড়া','দক্ষ']],
            'assigned_team' => ['নির্ধারিত এলাকা/টিম', 'text'],
            'status' => ['স্ট্যাটাস', 'select', ['সক্রিয়','অপেক্ষমাণ','নির্বাচিত নয়']],
            'card_issued' => ['আইডি/কার্ড ইস্যু', 'select', ['হ্যাঁ','না']],
            'card_issue_date' => ['কার্ড ইস্যুর তারিখ', 'date'],
            'approver_name' => ['অনুমোদনকারী নাম/পদবি', 'text'],
            'approver_date' => ['অনুমোদনের তারিখ', 'date'],
            'office_notes' => ['মন্তব্য/বিশেষ নির্দেশনা', 'textarea'],
            'response_timestamp' => ['Google Forms response timestamp', 'text'],
            'source_emergency_raw' => ['Excel-এর জরুরি যোগাযোগের পূর্ণ লেখা', 'textarea'],
            'source_work_raw' => ['Excel-এর পূর্ববর্তী কাজের পূর্ণ লেখা', 'textarea'],
            'source_training_raw' => ['Excel-এর প্রশিক্ষণের পূর্ণ লেখা', 'textarea'],
            'source_consent_raw' => ['Excel-এর মূল সম্মতিপত্রের পূর্ণ লেখা', 'textarea'],
        ]],
    ];
}
function all_fields(bool $admin = true): array {
    $all = [];
    foreach (form_sections() as $section) {
        if (!$admin && !empty($section['admin_only'])) continue;
        $all = array_merge($all, $section['fields']);
    }
    return $all;
}
function default_data(): array {
    $out = [];
    foreach (all_fields() as $key => $def) $out[$key] = $def[1] === 'multi' ? [] : '';
    return $out;
}
function sanitize_form_data(array $raw, bool $admin, array $existing = []): array {
    $clean = default_data();
    foreach (all_fields($admin) as $field => $def) {
        $value = $raw[$field] ?? null;
        if ($def[1] === 'multi') {
            $allowed = array_map('strval', array_keys($def[2]));
            if (array_is_list($def[2])) $allowed = array_values($def[2]);
            $items = is_array($value) ? array_slice($value, 0, 24) : [];
            $clean[$field] = array_values(array_unique(array_filter(array_map('strval', $items), fn($s) => in_array($s, $allowed, true))));
        } else {
            $str = is_scalar($value) ? trim((string)$value) : '';
            $str = mb_safe_limit($str, $def[1] === 'textarea' ? 6000 : 300);
            if ($def[1] === 'select' && $str !== '' && !in_array($str, $def[2], true)) $str = '';
            if ($def[1] === 'date' && $str !== '' && !valid_date($str)) $str = '';
            $clean[$field] = $str;
        }
    }
    foreach (['work_history' => ['org','duty','area','period','reference'], 'training_history' => ['title','organizer','period','certificate']] as $group => $columns) {
        $rows = is_array($raw[$group] ?? null) ? array_slice($raw[$group], 0, 4) : [];
        $clean[$group] = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $row = [];
            foreach ($columns as $col) $row[$col] = mb_safe_limit(is_scalar($r[$col] ?? null) ? trim((string)$r[$col]) : '', 220);
            if (implode('', $row) !== '') $clean[$group][] = $row;
        }
    }
    if (!$admin) foreach (all_fields(true) as $key => $def) {
        if (!array_key_exists($key, $clean)) $clean[$key] = $existing[$key] ?? ($def[1] === 'multi' ? [] : '');
    }
    return $clean;
}
function mb_safe_limit(string $text, int $max): string {
    if (function_exists('mb_substr')) return mb_substr($text, 0, $max);
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($characters) ? implode('', array_slice($characters, 0, $max)) : substr($text, 0, $max);
}
function valid_date(string $date): bool {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}
