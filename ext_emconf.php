<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'hostily Captcha',
    'description' => 'Spamschutz von hostily (captcha.hostily.de) für EXT:form und powermail. / Spam protection by hostily (captcha.hostily.de) for EXT:form and powermail.',
    'category' => 'plugin',
    'author' => 'hostily',
    'author_email' => '',
    'state' => 'beta',
    'version' => '0.2.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'form' => '',
            'powermail' => '12.4.0-13.99.99',
        ],
    ],
];
