<?php
// Subject icon for LMS course cards/rows (roles/lms/my_courses.php, lms_home.php).
// Picks a decorative illustration matching what the subject actually
// is, by keyword against subjects.subject_name (checked against the
// real 47 distinct names in this DB) — not just a flat color per
// card. Order matters: more specific categories (Business) are
// checked before broader ones (Social Science) so a name like
// "Business Ethics and Social Responsibility" lands correctly.
function subject_banner_icon(string $subjectName): string {
    $name = mb_strtolower($subjectName);
    $categories = [
        'math'     => ['calculus', 'mathematics', 'statistics and probability'],
        'biology'  => ['biology'],
        'chemistry'=> ['chemistry'],
        'physics'  => ['physics'],
        'computer' => ['computer', 'programming', 'systems servicing', 'systems analysis', 'web development', 'networks and critical thinking'],
        'business' => ['economics', 'business', 'entrepreneurship', 'accountancy', 'management', 'marketing'],
        'religion' => ['religion'],
        'research' => ['research'],
        'media'    => ['media and information'],
        'immersion'=> ['work immersion'],
        'social'   => ['social science', 'social sciences', 'politics and governance', 'citizenship', 'disaster readiness'],
        'language' => ['communication', 'reading and writing', 'creative writing', 'creative nonfiction'],
        'arts'     => ['arts from the regions', 'humanities'],
    ];

    $svgAttrs = 'viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
        'math' => "<svg $svgAttrs><path d=\"M15 90 L15 10\"/><path d=\"M10 85 L90 85\"/><path d=\"M20 70 Q45 20 65 50 T90 15\"/></svg>",
        'biology' => "<svg $svgAttrs><path d=\"M20 5 L80 25 L20 45 L80 65 L20 85 L80 95\"/><path d=\"M80 5 L20 25 L80 45 L20 65 L80 85 L20 95\"/></svg>",
        'chemistry' => "<svg $svgAttrs><path d=\"M40 8 L40 35 L15 82 Q12 90 22 90 L78 90 Q88 90 85 82 L60 35 L60 8\"/><path d=\"M33 8 L67 8\"/><circle cx=\"38\" cy=\"70\" r=\"4\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"58\" cy=\"76\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"48\" cy=\"58\" r=\"3\" fill=\"currentColor\" stroke=\"none\"/></svg>",
        'physics' => "<svg viewBox=\"0 0 100 100\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"4\"><circle cx=\"50\" cy=\"50\" r=\"6\" fill=\"currentColor\" stroke=\"none\"/><ellipse cx=\"50\" cy=\"50\" rx=\"45\" ry=\"18\"/><ellipse cx=\"50\" cy=\"50\" rx=\"45\" ry=\"18\" transform=\"rotate(60 50 50)\"/><ellipse cx=\"50\" cy=\"50\" rx=\"45\" ry=\"18\" transform=\"rotate(120 50 50)\"/></svg>",
        'computer' => "<svg viewBox=\"0 0 100 100\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"6\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M35 20 L10 50 L35 80\"/><path d=\"M65 20 L90 50 L65 80\"/><path d=\"M58 12 L42 88\"/></svg>",
        'business' => "<svg $svgAttrs><rect x=\"10\" y=\"35\" width=\"80\" height=\"50\" rx=\"5\"/><path d=\"M35 35 L35 22 Q35 15 42 15 L58 15 Q65 15 65 22 L65 35\"/><path d=\"M10 55 L90 55\"/><rect x=\"25\" y=\"62\" width=\"10\" height=\"15\" fill=\"currentColor\" stroke=\"none\"/><rect x=\"45\" y=\"52\" width=\"10\" height=\"25\" fill=\"currentColor\" stroke=\"none\"/><rect x=\"65\" y=\"45\" width=\"10\" height=\"32\" fill=\"currentColor\" stroke=\"none\"/></svg>",
        'religion' => "<svg $svgAttrs><path d=\"M50 88 C20 65, 8 45, 8 28 C8 12, 25 5, 38 15 Q50 25, 50 30 Q50 25, 62 15 C75 5, 92 12, 92 28 C92 45, 80 65, 50 88 Z\"/></svg>",
        'research' => "<svg $svgAttrs><rect x=\"10\" y=\"8\" width=\"55\" height=\"70\" rx=\"4\"/><path d=\"M22 25 L53 25 M22 38 L53 38 M22 51 L40 51\"/><circle cx=\"68\" cy=\"65\" r=\"18\"/><path d=\"M81 78 L95 92\"/></svg>",
        'media' => "<svg $svgAttrs><rect x=\"8\" y=\"28\" width=\"84\" height=\"58\" rx=\"8\"/><path d=\"M35 28 L40 15 L60 15 L65 28\"/><circle cx=\"50\" cy=\"57\" r=\"19\"/></svg>",
        'immersion' => "<svg $svgAttrs><rect x=\"8\" y=\"38\" width=\"84\" height=\"48\" rx=\"5\"/><path d=\"M35 38 L35 22 Q35 15 44 15 L56 15 Q65 15 65 22 L65 38\"/><path d=\"M8 60 L38 60 M62 60 L92 60\"/><rect x=\"38\" y=\"52\" width=\"24\" height=\"16\" fill=\"currentColor\" stroke=\"none\"/></svg>",
        'social' => "<svg viewBox=\"0 0 100 100\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"4\" stroke-linecap=\"round\"><circle cx=\"50\" cy=\"50\" r=\"42\"/><path d=\"M8 50 L92 50 M50 8 L50 92\"/><path d=\"M22 22 Q50 50 22 78 M78 22 Q50 50 78 78\"/></svg>",
        'language' => "<svg $svgAttrs><path d=\"M50 25 Q30 12 10 20 L10 78 Q30 70 50 82 Q70 70 90 78 L90 20 Q70 12 50 25 Z\"/><path d=\"M50 25 L50 82\"/></svg>",
        'arts' => "<svg $svgAttrs><path d=\"M50 10 Q15 10 15 42 Q15 65 38 65 Q45 65 45 57 Q45 50 52 50 Q88 50 88 25 Q88 10 50 10 Z\"/><circle cx=\"32\" cy=\"28\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"50\" cy=\"22\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"68\" cy=\"26\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"72\" cy=\"40\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/></svg>",
    ];
    // Fallback: generic open book — same shape as 'language', a
    // reasonable default for "a subject" in general.
    $fallback = "<svg $svgAttrs><path d=\"M50 25 Q30 12 10 20 L10 78 Q30 70 50 82 Q70 70 90 78 L90 20 Q70 12 50 25 Z\"/><path d=\"M50 25 L50 82\"/></svg>";

    foreach ($categories as $key => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($name, $kw)) return $icons[$key];
        }
    }
    return $fallback;
}
