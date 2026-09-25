<?php

declare(strict_types=1);

return [
    // 利用者提供の原本をbyte-for-byte複製した読み取り専用テンプレート。変更時はhashとmappingを同時に見直す。
    'template_file' => 'ark-jiyugaoka-2026-10-source.xlsx',
    'template_sha256' => '739c6dd1655ced0e4f7c47c3cb7e595a2f52d78ba3a8480fe55d2f438268c9d6',
    'template_version' => 'ark-jiyugaoka-2026-10-v1',
    'source_period' => '2026-10',
    // 原本basenameは「が」が分解形(U+304B U+3099)。見た目だけでなく符号位置も保持する。
    'download_filename_pattern' => 'ARK自由が丘店%04d.%02d.xlsx',
];
