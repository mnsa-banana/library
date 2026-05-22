<?php

namespace Database\Seeders;

use App\Models\CategoryGroup;
use App\Models\Rating;
use App\Models\Report;
use Illuminate\Database\Seeder;

class ElioReportSeeder extends Seeder
{
    public function run(): void
    {
        $report = Report::updateOrCreate(
            ['content_type' => 'movie', 'title' => 'Elio', 'year' => '2025'],
            [
                'poster_url' => null,
                'certification' => 'PG',
                'plot_synopsis' => 'An imaginative young boy who struggles to fit in on Earth and connect with his aunt, a high-ranking military official, accidentally intercepts a signal from the Communiverse—an intergalactic organization of advanced alien species. He is beamed up into space and mistakenly identified as the leader of Earth. He must team up with a young alien to prevent a ruthless warlord from destroying the peaceful alien community, learning valuable lessons about family and belonging along the way.',
                'critical_reception' => 'The film generally received positive reviews as a crowd-pleasing, emotionally resonant Pixar adventure with beautiful animation and strong voice acting. While critics praised its heartfelt messages about loneliness and found family, some noted that its fast-paced story felt slightly rushed compared to the studio\'s classic hits.',
                'parent_summary' => null,
                'is_adaptation' => false,
                'source_material' => 'This film is an original work by Pixar Animation Studios and Walt Disney Pictures and is not based on any pre-existing narrative source material.',
            ]
        );

        $this->seedCategoryGroups($report);
        $this->seedRatings($report);
    }

    private function seedCategoryGroups(Report $report): void
    {
        $groups = [
            ['content_and_intensity', 'humor', 'Humor occasionally leans into mild gross-out territory, including implied vomiting, a poop emoji, and references to bodily functions.'],
            ['content_and_intensity', 'language', 'Language is very mild, limited to a few minced oaths (like \'gosh\' and \'heck\') and childish insults. In one comedic moment, a character speaks a made-up alien language, and the on-screen English subtitles translate his gibberish into symbol characters (like \'@#$%\') to jokingly imply he is swearing.'],
            ['content_and_intensity', 'violence', 'Action primarily consists of animated sci-fi peril with spaceships and laser blasts, alongside some grounded childhood scuffles.'],
            ['content_and_intensity', 'sexuality', 'The film is completely free of romantic, sexual, and immodest content.'],
            ['content_and_intensity', 'substances', 'Substance use is entirely limited to an adult having a glass of wine with dinner.'],
            ['content_and_intensity', 'emotional_intensity', 'The emotional weight centers on an orphaned boy actively grieving the loss of his parents and navigating feelings of loneliness, alongside depictions of childhood bullying.'],
            ['themes_and_depictions', 'behavior', 'The young protagonist engages in sustained deception by lying to friendly aliens about his identity to stay in space, and he and his aunt break into a restricted military base, but no cheating or gambling occurs.'],
            ['themes_and_depictions', 'magic_and_supernatural', 'The setting is strictly science fiction filled with diverse alien species; no magic or occult elements are present, though a villain\'s spaceship is decorated with macabre skeletal remains.'],
            ['themes_and_depictions', 'relationships_and_family', 'The family narrative focuses entirely on a single guardian raising her orphaned nephew; no romantic relationships, LGBTQ storylines, or marital conflicts are present.'],
            ['messaging_and_worldview', 'messaging', 'The film promotes positive themes of family reconciliation, honesty, and self-sacrifice, and does not contain anti-authority or feminist messaging.'],
            ['messaging_and_worldview', 'worldview_themes', 'The narrative does not contain political or ideological messaging. The film features diverse casting and mostly features non-white male characters, but the story itself does not engage in identity politics.'],
            ['messaging_and_worldview', 'adaptation_fidelity', 'As an original work, \'Elio\' does not adapt any pre-existing narrative. Consequently, discussions regarding character race or gender changes from source material are not applicable.'],
            ['christian_perspectives', 'christian_perspectives', 'The film features strong moral themes of self-sacrifice, family reconciliation, and putting others before oneself, without any blasphemy or anti-faith messaging.'],
        ];

        foreach ($groups as [$section, $group, $notes]) {
            CategoryGroup::updateOrCreate(
                ['report_id' => $report->id, 'section_key' => $section, 'group_key' => $group],
                ['notes' => $notes]
            );
        }
    }

    private function seedRatings(Report $report): void
    {
        $ratings = [
            // content_and_intensity.humor
            ['content_and_intensity', 'humor', 'dark_morbid', false, null, 'No dark or morbid humor trivializing death or suffering appears.'],
            ['content_and_intensity', 'humor', 'religious_humor', false, null, 'No religion, faith, or spiritual practice is mocked or used as a punchline.'],
            ['content_and_intensity', 'humor', 'sexual_innuendo', false, null, 'No sexual innuendo or double entendres are present.'],
            ['content_and_intensity', 'humor', 'potty_bodily_function', true, null, 'Elio and his alien friend Glordon over-consume a colorful alien drink and are later shown motion-sick and vomiting, though the vomit itself is not shown on screen. An alien spaceship features a \'bladder evacuation module.\' Aunt Olga uses a poop emoji in a text message, and a couple of mild \'butt\' jokes are made.'],

            // content_and_intensity.language
            ['content_and_intensity', 'language', 'slurs', false, null, 'No real-world slurs are used.'],
            ['content_and_intensity', 'language', 'insults', true, null, 'Mild insults are used occasionally. A bully at the beach calls Elio a \'freak\' during a scuffle, and a military colleague calls a conspiracy theorist a \'nutjob\'. Elio and a camper named Bryce call a bully a \'butt\' twice.'],
            ['content_and_intensity', 'language', 'mild_curses', false, null, 'No mild curses are spoken. A camper starts to exclaim \'What the...\' when seeing a spaceship, but he is cut off before finishing the phrase.'],
            ['content_and_intensity', 'language', 'minced_oaths', true, null, '\'Oh, my gosh\' appears 3 times (e.g., a kid at the beach says \'Oh, my gosh, you\'re right. It\'s spreading\', and Olga says it while flying a spaceship). \'Heck\' is used once when Elio says \'Heck, yeah\' before doing a stunt. \'Darn\' is used once by Elio when pretending to be a tough leader, claiming he \'left my arm cannon at home.\' \'Shoot\' is used once by Olga in frustration when she drops a flashlight.'],
            ['content_and_intensity', 'language', 'vulgar_gestures', false, null, 'No characters perform crude or obscene gestures.'],
            ['content_and_intensity', 'language', 'anatomical_slang', false, null, 'No vulgar anatomical slang is used.'],
            ['content_and_intensity', 'language', 'gendered_insults', false, null, 'No gender-specific insults or slurs are used.'],
            ['content_and_intensity', 'language', 'strong_profanity', false, null, 'No strong profanity is used.'],
            ['content_and_intensity', 'language', 'silly_substitutes', false, null, 'No characters use clean substitute words in place of specific profanity.'],
            ['content_and_intensity', 'language', 'explicit_sexual_language', false, null, 'No crude sexual terminology is used.'],

            // content_and_intensity.violence
            ['content_and_intensity', 'violence', 'max_level', null, 'Moderate', 'Action involves bloodless sci-fi peril and mild physical scuffles. Elio is bullied, gets into a fistfight, poked in the eye requiring an eyepatch. Sci-fi setting features space debris, lava tunnels, and laser blasts. A cell contains a skeleton. Clay clones crumble into dust.'],
            ['content_and_intensity', 'violence', 'gun_violence', false, null, 'Characters use sci-fi arm cannons and spaceship turrets that fire energy blasts, but no realistic firearms are used.'],
            ['content_and_intensity', 'violence', 'animal_violence', false, null, 'No real-world animals are harmed or subjected to cruelty.'],
            ['content_and_intensity', 'violence', 'sexual_violence', false, null, 'No sexual violence or assault occurs.'],
            ['content_and_intensity', 'violence', 'domestic_violence', false, null, 'No sustained physical abuse between family members occurs.'],

            // content_and_intensity.sexuality
            ['content_and_intensity', 'sexuality', 'max_level', null, 'None', 'No romantic or sexual content appears in the film. There are no romantic subplots, crushes, kisses, or depictions of married couples showing physical affection.'],
            ['content_and_intensity', 'sexuality', 'immodesty', false, null, 'No immodest costuming, sexualized imagery, or lingering camera work appears.'],
            ['content_and_intensity', 'sexuality', 'non_sexual_nudity', false, null, 'No characters are shown nude in a non-sexual context.'],
            ['content_and_intensity', 'sexuality', 'pornography_present', false, null, 'No pornographic material or adult content is shown or referenced.'],

            // content_and_intensity.substances
            ['content_and_intensity', 'substances', 'alcohol', true, null, 'Aunt Olga has a glass of wine with her dinner.'],
            ['content_and_intensity', 'substances', 'drunkenness', false, null, 'No characters are shown intoxicated or visibly affected by alcohol.'],
            ['content_and_intensity', 'substances', 'tobacco_smoking', false, null, 'No characters smoke cigarettes, cigars, or pipes.'],
            ['content_and_intensity', 'substances', 'vaping', false, null, 'No e-cigarettes or vaping devices are used.'],
            ['content_and_intensity', 'substances', 'marijuana', false, null, 'No marijuana or cannabis references appear.'],
            ['content_and_intensity', 'substances', 'hard_drugs', false, null, 'No hard drugs are used or referenced.'],
            ['content_and_intensity', 'substances', 'prescription_drug_misuse', false, null, 'No prescription medications are misused.'],
            ['content_and_intensity', 'substances', 'underage_substance_use', false, null, 'No minors consume alcohol or drugs.'],

            // content_and_intensity.emotional_intensity
            ['content_and_intensity', 'emotional_intensity', 'overall_level', null, 'Moderate', 'Elio struggles with the prior loss of his parents in an accident, expressing deep loneliness and feelings of being unwanted. In an emotional moment, he cries out, \'The only people who wanted me are gone.\' The grief is central to his character arc but is balanced with positive family themes and does not escalate to raw, devastating anguish.'],
            ['content_and_intensity', 'emotional_intensity', 'death_of_parent', true, null, 'Elio\'s parents died in an accident before the events of the story. He visibly processes this loss and feelings of isolation on screen, crying and stating, \'The only people who wanted me are gone.\''],
            ['content_and_intensity', 'emotional_intensity', 'death_of_child', false, null, 'No child characters die.'],
            ['content_and_intensity', 'emotional_intensity', 'death_of_pet', false, null, 'No pets or animal companions die.'],
            ['content_and_intensity', 'emotional_intensity', 'themes_of_abandonment', false, null, 'No parental abandonment occurs. Elio briefly fears his aunt is sending him to camp to get rid of him, but she is actually trying to help him and remains a dedicated, loving guardian.'],
            ['content_and_intensity', 'emotional_intensity', 'suicide_self_harm', false, null, 'No characters attempt or contemplate suicide or self-harm.'],
            ['content_and_intensity', 'emotional_intensity', 'bullying', true, null, 'A group of older boys target Elio at a summer camp, chasing him through dark woods to scare him. In a separate physical altercation, two kids hold him down while a third attempts to punch him, exploiting a clear numerical power imbalance.'],
            ['content_and_intensity', 'emotional_intensity', 'frightening_disturbing_imagery', true, null, 'A sequence involving a clay clone of Elio features a sudden, creepy jump-scare. Additionally, an alien named Glordon initially frightens the boy by abruptly wrapping him tightly in a web-like material.'],
            ['content_and_intensity', 'emotional_intensity', 'eating_disorders', false, null, 'No eating disorders or obsessive food restrictions are depicted.'],

            // themes_and_depictions.relationships_and_family
            ['themes_and_depictions', 'relationships_and_family', 'explicit_characters_or_relationships', false, null, 'No characters are identified as LGBTQ, and no same-sex romantic relationships are depicted.'],
            ['themes_and_depictions', 'relationships_and_family', 'implied_or_coded', false, null, 'No queerbaiting or ambiguously coded same-sex romantic relationships appear.'],
            ['themes_and_depictions', 'relationships_and_family', 'cohabitation_without_marriage', false, null, 'No unmarried romantic couples are depicted sharing a home.'],
            ['themes_and_depictions', 'relationships_and_family', 'divorce', false, null, 'No divorce storylines or separated parents are depicted.'],
            ['themes_and_depictions', 'relationships_and_family', 'single_parenthood_by_choice', false, null, 'Single parenthood is depicted only through circumstance, as Aunt Olga raises her nephew following the sudden death of his parents.'],
            ['themes_and_depictions', 'relationships_and_family', 'affair_infidelity', false, null, 'No romantic affairs or infidelity occur.'],

            // themes_and_depictions.behavior
            ['themes_and_depictions', 'behavior', 'lying_and_deception', true, null, 'Elio deliberately lies to the Communiverse, a peaceful council of aliens, falsely claiming to be the \'leader of Earth\' out of fear they will send him home if they discover he is just a child. He maintains this elaborate deception toward the friendly aliens throughout much of the story.'],
            ['themes_and_depictions', 'behavior', 'stealing_and_theft', true, null, 'Elio breaks rules and trespasses to use restricted military communication equipment without permission, causing a power outage at a government base. Later, he and Aunt Olga break into a restricted military area and commandeer an impounded alien spacecraft to rescue a friend.'],
            ['themes_and_depictions', 'behavior', 'cheating', false, null, 'No protagonist-sympathizing characters cheat on tests, games, or competitions.'],
            ['themes_and_depictions', 'behavior', 'gambling', false, null, 'No characters gamble or place bets.'],
            ['themes_and_depictions', 'behavior', 'revenge', false, null, 'No protagonist-sympathizing characters pursue revenge or personal vengeance.'],

            // themes_and_depictions.magic_and_supernatural
            ['themes_and_depictions', 'magic_and_supernatural', 'dark_creatures', true, null, 'The alien warlord Lord Grigon\'s spaceship features macabre interior decor, including alien skulls displayed as trophies. At one point, Elio is briefly imprisoned in a cell containing the skeletal remains of a deceased prisoner.'],
            ['themes_and_depictions', 'magic_and_supernatural', 'practiced_magic', false, null, 'No spell-casting, potion-brewing, or real-world occult terminology is used.'],
            ['themes_and_depictions', 'magic_and_supernatural', 'mythical_creatures', true, null, 'A wide variety of fantastical alien species appear throughout the story, including an entity named Ooooo that functions as a liquid supercomputer, an alien named Questa with mind-reading abilities, and characters like Glordon and Lord Grigon who resemble larval worms with multiple rows of teeth.'],
            ['themes_and_depictions', 'magic_and_supernatural', 'occult_and_demonic', false, null, 'No demonic entities, possessions, or real-world occult rituals are depicted.'],
            ['themes_and_depictions', 'magic_and_supernatural', 'pagan_gods_mythology', false, null, 'No non-biblical deities or figures from pagan mythology are depicted.'],
            ['themes_and_depictions', 'magic_and_supernatural', 'fairy_tale_fantasy_magic', false, null, 'No fairy-tale or fantasy magic is present. The story relies entirely on science fiction concepts—such as advanced alien technology, cloning clay, and liquid supercomputers—rather than magical powers.'],

            // messaging_and_worldview.messaging
            ['messaging_and_worldview', 'messaging', 'male_incompetence', false, null, 'No main male characters are portrayed as consistently incompetent buffoons. The story highlights the importance of a loving father and gives heroic moments to male characters.'],
            ['messaging_and_worldview', 'messaging', 'feminist_messaging', false, null, 'No feminist messaging or deconstruction of traditional gender roles is present. A female character explicitly chooses her family responsibilities over her ambitious career goals, which is framed positively.'],
            ['messaging_and_worldview', 'messaging', 'anti_parental_authority', false, null, 'No anti-parental authority messaging is present. While a child disobeys his guardian early in the film, his defiance is framed as a struggle with grief rather than empowerment. The story resolves with him reconciling with her and choosing family over running away.'],
            ['messaging_and_worldview', 'messaging', 'moral_relativism', false, null, 'The narrative does not argue that morality is subjective or relative.'],
            ['messaging_and_worldview', 'messaging', 'ends_justify_means', false, null, 'The protagonist\'s deception is not validated by the narrative. When his lie is discovered, he is sternly rebuked by the other characters, and he apologizes for his actions. His ultimate redemption comes through self-sacrifice and making peace, not from the success of his deception.'],
            ['messaging_and_worldview', 'messaging', 'body_positivity', false, null, 'No body positivity messaging is present.'],

            // messaging_and_worldview.worldview_themes
            ['messaging_and_worldview', 'worldview_themes', 'anti_military', false, null, 'No anti-military messaging is present. A prominent, respected character is a military major, and military service is not denigrated.'],
            ['messaging_and_worldview', 'worldview_themes', 'anti_authority', false, null, 'Authority is not depicted as inherently corrupt or oppressive.'],
            ['messaging_and_worldview', 'worldview_themes', 'anti_capitalism', false, null, 'No ideological critiques of wealth or capitalism appear.'],
            ['messaging_and_worldview', 'worldview_themes', 'anti_patriotism', false, null, 'No critiques of patriotism or national symbols occur.'],
            ['messaging_and_worldview', 'worldview_themes', 'environmentalism', false, null, 'No environmentalist or climate-related messaging is present.'],
            ['messaging_and_worldview', 'worldview_themes', 'identity_politics', false, null, 'Race, gender, and sexuality are not used as primary character identities, and no identity-based grievances drive the plot.'],

            // messaging_and_worldview.adaptation_fidelity
            ['messaging_and_worldview', 'adaptation_fidelity', 'race_swapped_characters', false, null, 'The film is an original creation and not an adaptation of any existing narrative. Therefore, the concept of race-swapped characters does not apply.'],
            ['messaging_and_worldview', 'adaptation_fidelity', 'gender_swapped_characters', false, null, 'The film is an original creation and not an adaptation of any existing narrative. Therefore, the concept of gender-swapped characters does not apply.'],

            // christian_perspectives.christian_perspectives
            ['christian_perspectives', 'christian_perspectives', 'blasphemy_casual', false, null, 'No casual uses of God\'s name or Jesus\'s name appear. Characters say \'Oh, my gosh,\' which is a clean minced oath.'],
            ['christian_perspectives', 'christian_perspectives', 'blasphemy_profanity', false, null, 'God\'s name is not paired with profanity.'],
            ['christian_perspectives', 'christian_perspectives', 'blasphemy_mockery', false, null, 'The entirely secular sci-fi setting contains no mentions of religion, God, or Christian practice, and therefore no mockery of them.'],
            ['christian_perspectives', 'christian_perspectives', 'non_christian_religious_subtext', false, null, 'No non-Christian religious or spiritual systems are presented as true or aspirational within the narrative.'],
            ['christian_perspectives', 'christian_perspectives', 'afterlife_contradicted', false, null, 'The narrative makes no claims about the afterlife or what happens to souls after death.'],
            ['christian_perspectives', 'christian_perspectives', 'evolution_as_fact', false, null, 'While the film\'s sci-fi premise features alien life across the universe, Darwinian evolution is never explicitly mentioned or invoked as fact by the narrator or characters.'],
            ['christian_perspectives', 'christian_perspectives', 'secular_humanism', false, null, 'No secular humanist messaging is present.'],
            ['christian_perspectives', 'christian_perspectives', 'moral_authority_outside_scripture', false, null, 'No moral authority outside Scripture is promoted.'],
            ['christian_perspectives', 'christian_perspectives', 'faith_portrayed_negatively', false, null, 'Christian faith and believers are not attacked or portrayed negatively.'],
            ['christian_perspectives', 'christian_perspectives', 'redemptive_value', null, 'Moderate', 'Elio repeatedly risks his own safety to protect a peaceful alien community from a warlord, stepping up when others are too afraid. Ultimately, he makes a difficult personal sacrifice by giving up his deepest desire to stay in a perfect alien utopia, realizing his true responsibility is to return to Earth and reconcile with his grieving aunt. This character arc demonstrates clear moral growth, emphasizing that true belonging is found through love, honesty, and family bonds.'],
            ['christian_perspectives', 'christian_perspectives', 'self_actualization_as_moral_framework', false, null, 'The narrative does not promote self-actualization or \'follow your heart\' as its ultimate moral framework. Instead, the story resolves through self-sacrifice, forgiveness, and Elio choosing to reconcile with his family on Earth rather than escaping to an alien utopia.'],
        ];

        foreach ($ratings as [$section, $group, $subcategory, $present, $level, $evidence]) {
            Rating::updateOrCreate(
                [
                    'report_id' => $report->id,
                    'section_key' => $section,
                    'group_key' => $group,
                    'subcategory_key' => $subcategory,
                ],
                [
                    'present' => $present,
                    'level' => $level,
                    'evidence' => $evidence,
                ]
            );
        }
    }
}
