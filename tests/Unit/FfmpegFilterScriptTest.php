<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ffmpeg\FfmpegFilterScript;
use Tests\TestCase;

final class FfmpegFilterScriptTest extends TestCase
{
    public function test_ffmpeg_6_uses_the_legacy_option(): void
    {
        $this->assertSame(
            FfmpegFilterScript::OPTION_LEGACY,
            FfmpegFilterScript::optionFor(6, ''),
        );
    }

    public function test_ffmpeg_7_and_later_use_the_file_option(): void
    {
        $this->assertSame(FfmpegFilterScript::OPTION_MODERN, FfmpegFilterScript::optionFor(7, ''));
        $this->assertSame(FfmpegFilterScript::OPTION_MODERN, FfmpegFilterScript::optionFor(9, ''));
    }

    public function test_a_git_build_falls_back_to_probing_the_help(): void
    {
        $legacyHelp = "  -filter_complex_script <filename>  read complex filtergraph description from a file\n";

        $this->assertSame(
            FfmpegFilterScript::OPTION_LEGACY,
            FfmpegFilterScript::optionFor(null, $legacyHelp),
        );

        $this->assertSame(
            FfmpegFilterScript::OPTION_MODERN,
            FfmpegFilterScript::optionFor(null, "  -filter_complex <graph>  set complex filtergraph\n"),
        );
    }

    public function test_it_reads_the_major_version_from_the_first_line(): void
    {
        $this->assertSame(9, FfmpegFilterScript::majorVersion(
            "ffmpeg version 9.0.1 Copyright (c) 2000-2026 the FFmpeg developers\nbuilt with clang\n",
        ));

        $this->assertSame(6, FfmpegFilterScript::majorVersion(
            "ffmpeg version 6.1.1-3ubuntu5 Copyright (c) 2000-2023 the FFmpeg developers\n",
        ));

        $this->assertSame(7, FfmpegFilterScript::majorVersion("ffmpeg version 7.1.git\n"));
    }

    public function test_a_version_without_a_number_has_no_major(): void
    {
        $this->assertNull(FfmpegFilterScript::majorVersion(
            "ffmpeg version N-120000-gabcdef123 Copyright (c) 2000-2026 the FFmpeg developers\n",
        ));

        $this->assertNull(FfmpegFilterScript::majorVersion(''));
    }

    public function test_it_resolves_an_option_for_the_installed_binary(): void
    {
        $option = $this->app->make(FfmpegFilterScript::class)->option();

        $this->assertContains($option, [
            FfmpegFilterScript::OPTION_LEGACY,
            FfmpegFilterScript::OPTION_MODERN,
        ]);

        $this->assertSame(
            [$option, '/tmp/filter.txt'],
            $this->app->make(FfmpegFilterScript::class)->arguments('/tmp/filter.txt'),
        );
    }
}
