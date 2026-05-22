<?php

// Copy for the MNSA landing page. Edit this file directly to set the real
// marketing copy; currently populated from mnsa-landing-copy.local.json.

return [
    'brands' => [
        'mnsa-safe' => [
            'meta' => [
                'title' => 'Make Netflix Safe Again — take back control of what your family watches',
                'description' => "A one-click Chrome extension that batch-applies content restrictions to your Netflix profiles, using Imbuo's title-by-title content analysis — so the stuff you don't want on the screen never shows up.",
                'ogImage' => '',
            ],
            'brandNameLead' => 'Make Netflix',
            'brandNameAccent' => 'Safe Again',
            'nav' => [
                'cta' => 'Get the extension',
                'signIn' => 'Sign in',
            ],
            'hero' => [
                'eyebrow' => "Chrome extension · built on Imbuo's content analysis",
                'titleLead' => 'Make Netflix',
                'titleAccent' => 'Safe Again',
                'body' => "Netflix's parental controls make you block titles one at a time, through a search box, per profile. Our extension does the whole list in one click — every title flagged by Imbuo's reviewers, applied to the profiles you choose.",
                'primaryCta' => 'Get the extension',
                'secondaryCta' => 'See how it works',
                'note' => 'Free to install · you authenticate with Netflix yourself · nothing is sent to us.',
            ],
            'problem' => [
                'label' => 'The problem',
                'heading' => 'Netflix gives you a search box and a one-by-one workflow.',
                'lede' => "If you've ever tried to lock down a kids' profile, you know the drill: search a title, click it, confirm, repeat. There's no \"block all of these\" button — so most parents give up after a dozen.",
                'arguments' => [
                    ['kicker' => '01 · Manual', 'title' => 'One title at a time', 'body' => "Netflix's restrictions page only adds titles through its search box, individually. No bulk import, no list upload."],
                    ['kicker' => '02 · Per profile', 'title' => 'Then do it again', 'body' => 'Restrictions are per-profile. Every profile you care about means starting the whole list over from scratch.'],
                    ['kicker' => '03 · No source', 'title' => "And you're the curator", 'body' => "You have to know what to block. Imbuo's reviewers already track it — this just connects the two."],
                ],
            ],
            'demo' => [
                'label' => 'The demo',
                'heading' => 'Click once. Review the list. Done.',
                'lede' => "The extension reads the list from your Imbuo account, matches each title against Netflix's catalog, and queues the restrictions for you to confirm — you scan the list, deselect anything you want to keep, and apply.",
                'window' => [
                    'title' => 'Profile restrictions — Kids',
                    'hint' => 'Titles below are blocked on this profile.',
                    'searchPlaceholder' => 'Add a title…',
                    'items' => ['The example show', 'Another example title', 'Something on the list', 'One more flagged title', '…and so on'],
                    'save' => 'Save',
                    'cancel' => 'Cancel',
                ],
                'panel' => [
                    'title' => 'Make Netflix Safe Again',
                    'metaLine' => "12 titles to add\non this profile",
                    'metaLink' => 'Review',
                    'candidates' => [
                        ['title' => 'Example Title One', 'details' => '2024 · Series', 'flag' => 'Flagged'],
                        ['title' => 'Example Title Two', 'details' => '2023 · Film', 'flag' => 'Flagged'],
                    ],
                    'installCta' => 'Apply',
                ],
                'vanishNote' => [
                    'title' => "Already restricted? They won't show up twice.",
                    'body' => "The extension reads the profile's current restrictions and only queues titles that aren't already blocked, so re-running it is always safe.",
                ],
            ],
            'workflow' => [
                'label' => 'How it works',
                'heading' => 'Three steps, about a minute.',
                'lede' => "You stay in control the whole time — the extension never touches Netflix without you watching.",
                'steps' => [
                    ['title' => 'Install & sign in', 'body' => "Add the extension and sign in to your Imbuo account. That's where the title list lives."],
                    ['title' => 'Pick a profile', 'body' => "Open Netflix's restrictions page, choose the profile, and enter your Netflix password — the same prompt Netflix always shows."],
                    ['title' => 'Review & apply', 'body' => 'The extension queues the restrictions; you scan the list, deselect anything you want to keep, and confirm.'],
                ],
            ],
            'features' => [
                'label' => 'What you get',
                'heading' => 'Built to be boring and reliable.',
                'lede' => "No magic, no API tricks — it drives the same restrictions page you'd use by hand, just faster.",
                'items' => [
                    ['tag' => 'Bulk', 'title' => 'The whole list at once', 'body' => 'Hundreds of titles in one pass instead of one search at a time.'],
                    ['tag' => 'Safe', 'title' => 'You authenticate', 'body' => 'We never see your Netflix credentials. The extension runs in your browser, on your session.'],
                    ['tag' => 'Curated', 'title' => "Imbuo's analysis", 'body' => "The list comes from Imbuo's content reviewers, kept current as new titles are reviewed."],
                    ['tag' => 'Repeatable', 'title' => 'Re-run anytime', 'body' => "New titles get added to the list; re-run the extension to catch them, skipping what's already blocked."],
                ],
            ],
            'faq' => [
                'label' => 'FAQ',
                'heading' => 'Questions',
                'items' => [
                    ['q' => 'Does this need my Netflix password?', 'a' => 'You enter it into Netflix, not into us — the same prompt Netflix shows when you edit restrictions. The extension never reads or stores it.'],
                    ['q' => "Will it un-block anything?", 'a' => "No. It only adds restrictions, never removes them. Anything you've already blocked stays blocked."],
                    ['q' => 'Where does the list come from?', 'a' => "Imbuo's content analysis — the same catalog of reviewed titles the parent app uses. It updates as new titles are reviewed."],
                ],
            ],
            'cta' => [
                'label' => 'Get started',
                'heading' => 'Stop blocking titles one at a time.',
                'body' => 'Create an account, install the extension, and lock down a profile in about a minute.',
                'button' => 'Get the extension',
            ],
            'footer' => [
                'taglineLead' => 'Make Netflix',
                'taglineAccent' => 'Safe Again',
                'links' => [
                    ['label' => 'The problem', 'href' => '#problem'],
                    ['label' => 'Demo', 'href' => '#demo'],
                    ['label' => 'How it works', 'href' => '#how'],
                    ['label' => 'Get started', 'href' => '#install'],
                ],
                'disclaimer' => '"Netflix" is a trademark of Netflix, Inc. This extension automates your own Netflix account\'s existing parental-controls page; it does not access Netflix\'s internal systems.',
            ],
        ],

        'mnsa-straight' => [
            'meta' => [
                'title' => 'Make Netflix Straight Again — Protect Your Kids from LGBTQ Content on Netflix',
                'description' => "Reclaim control of your family's Netflix. One-click Chrome extension that blocks every flagged LGBTQ title so your kids see content that aligns with your values.",
                'ogImage' => '',
            ],
            'brandNameLead' => 'Make Netflix',
            'brandNameAccent' => 'Straight Again',
            'nav' => [
                'cta' => 'Get the extension',
                'signIn' => 'Sign in',
            ],
            'hero' => [
                'eyebrow' => "Take back control of what your kids watch",
                'titleLead' => 'Make Netflix',
                'titleAccent' => 'Straight Again',
                'body' => "A recent study found that 41% of G-rated and TV-Y7 kids’ shows on Netflix now include LGBTQ themes and characters. Netflix offers parents almost no meaningful way to filter this. Our extension changes everything: one click blocks every flagged title across your chosen profiles — so your children’s screen time matches your family’s values and you control the conversation about sexuality.",
                'primaryCta' => 'Get the extension',
                'secondaryCta' => 'See how it works',
                'note' => 'Free to install · you authenticate with Netflix yourself · nothing is sent to us.',
            ],
            'problem' => [
                'label' => 'The problem',
                'heading' => 'Netflix is filling kids’ programming with LGBTQ content — and leaving parents powerless.',
                'lede' => "What used to be safe, age-appropriate entertainment now regularly introduces themes many families prefer to discuss at their own pace. Without a real filter, parents are forced to either accept it or spend hours manually blocking titles one by one.",
                'arguments' => [
                    ['kicker' => '01 · Overwhelming volume', 'title' => '41% of kids’ shows affected', 'body' => "Recent analysis shows 41% of G-rated and TV-Y7 content on Netflix includes LGBTQ themes — a dramatic shift that catches most parents by surprise."],
                    ['kicker' => '02 · No easy solution', 'title' => 'Tedious manual blocking', 'body' => 'Netflix forces you to search and block titles individually. It’s exhausting and never-ending as new content is added constantly.'],
                    ['kicker' => '03 · You deserve better', 'title' => "Reclaim your role", 'body' => "Parents should decide when and how these topics are introduced — not streaming platforms."],
                ],
            ],
            'demo' => [
                'label' => 'The demo',
                'heading' => 'One click. Your review. Your kids protected.',
                'lede' => "The extension pulls our carefully reviewed list of flagged titles, matches it to your Netflix catalog, and prepares every block. You review the list, deselect anything you want to keep, and apply — done in seconds.",
                'window' => [
                    'title' => 'Profile restrictions',
                    'hint' => 'Titles below are blocked on this profile.',
                    'searchPlaceholder' => 'Add a title…',
                    'items' => ['The example show', 'Another example title', 'Something on the list', 'One more flagged title', '…and so on'],
                    'save' => 'Save',
                    'cancel' => 'Cancel',
                ],
                'panel' => [
                    'title' => 'Make Netflix Straight Again',
                    'metaLine' => "37 titles to add\non this profile",
                    'metaLink' => 'Review',
                    'candidates' => [
                        ['title' => 'Example Title One', 'details' => '2024 · Series', 'flag' => 'Flagged'],
                        ['title' => 'Example Title Two', 'details' => '2023 · Film', 'flag' => 'Flagged'],
                    ],
                    'installCta' => 'Apply',
                ],
                'vanishNote' => [
                    'title' => "Already restricted? They won’t show up twice.",
                    'body' => "The extension checks your current blocks and only queues new ones, so you can safely run it again anytime.",
                ],
            ],
            'workflow' => [
                'label' => 'How it works',
                'heading' => 'Three simple steps. Full control in about a minute.',
                'lede' => "You remain completely in charge — the extension automates Netflix’s own restrictions page so you never lose oversight.",
                'steps' => [
                    ['title' => 'Install & connect', 'body' => "Add the free extension and sign into your account — where our regularly updated list of flagged titles lives."],
                    ['title' => 'Choose your profile', 'body' => "Go to Netflix’s restrictions page, select the profile, and enter your Netflix password — the same prompt Netflix always shows."],
                    ['title' => 'Review & apply', 'body' => 'Scan the flagged titles, deselect anything you prefer to keep, and confirm. Your family’s Netflix is now aligned with your values.'],
                ],
            ],
            'features' => [
                'label' => 'What you get',
                'heading' => 'Peace of mind for busy parents.',
                'lede' => "Simple, reliable protection that puts you back in control.",
                'items' => [
                    ['tag' => 'Fast', 'title' => 'Block dozens of titles instantly', 'body' => 'One pass instead of hours of manual searching.'],
                    ['tag' => 'Private', 'title' => 'You stay in full control', 'body' => 'You authenticate directly with Netflix. We never see or store your credentials.'],
                    ['tag' => 'Expert reviewed', 'title' => "Carefully curated list", 'body' => "Our team analyzes thousands of titles, flagging only clear LGBTQ content — accurate and updated regularly."],
                    ['tag' => 'Always current', 'title' => 'Re-run anytime', 'body' => "Netflix adds new titles? Run it again to catch the latest flagged content automatically."],
                ],
            ],
            'faq' => [
                'label' => 'FAQ',
                'heading' => 'Questions',
                'items' => [
                    ['q' => 'Does this need my Netflix password?', 'a' => 'Yes — but you enter it into Netflix’s own prompt, exactly as you would manually. The extension never reads or stores it.'],
                    ['q' => 'Will it un-block anything?', 'a' => 'Never. It only adds restrictions — it never removes existing blocks.'],
                    ['q' => 'Where does the list come from?', 'a' => "Our team watches and analyzes Netflix titles, flagging clear LGBTQ content so you don’t have to do the research yourself."],
                ],
            ],
            'cta' => [
                'label' => 'Get started',
                'heading' => 'Protect your kids’ Netflix in one click.',
                'body' => 'Create a free account, install the extension, and align your family’s Netflix with your values in about a minute.',
                'button' => 'Get the extension',
            ],
            'footer' => [
                'taglineLead' => 'Make Netflix',
                'taglineAccent' => 'Straight Again',
                'links' => [
                    ['label' => 'The problem', 'href' => '#problem'],
                    ['label' => 'Demo', 'href' => '#demo'],
                    ['label' => 'How it works', 'href' => '#how'],
                    ['label' => 'Get started', 'href' => '#install'],
                ],
                'disclaimer' => '"Netflix" is a trademark of Netflix, Inc. This extension automates your own Netflix account\'s existing parental-controls page; it does not access Netflix\'s internal systems.',
            ],
        ],
    ],
];
