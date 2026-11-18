<?php

namespace Tests\Unit;

use App\Services\Patient\SupportChannels;
use App\Services\Safety\GuardVerdict;
use PHPUnit\Framework\TestCase;

class SupportChannelsTest extends TestCase
{
    public function test_messages_without_a_medicine_are_recognized_by_need(): void
    {
        $this->assertSame(SupportChannels::VAGUE, SupportChannels::detect('Preciso de ajuda'));
        $this->assertSame(SupportChannels::VAGUE, SupportChannels::detect('me ajuda, por favor'));
        $this->assertSame(SupportChannels::EMOTIONAL, SupportChannels::detect('Estou passando por um momento difícil'));
        $this->assertSame(SupportChannels::EMOTIONAL, SupportChannels::detect('ando muito triste e ansiosa'));
        $this->assertSame(SupportChannels::SYMPTOM, SupportChannels::detect('Não estou me sentindo bem'));
        $this->assertSame(SupportChannels::SYMPTOM, SupportChannels::detect('estou com dor de cabeça e febre'));
        $this->assertSame(SupportChannels::VIOLENCE, SupportChannels::detect('meu marido me bate e tenho medo'));
        $this->assertSame(SupportChannels::SUBSTANCES, SupportChannels::detect('quero parar de beber, bebo demais'));
        $this->assertSame(SupportChannels::OTHER, SupportChannels::detect('Quero falar de outra coisa'));
        $this->assertNull(SupportChannels::detect('Tenho uma dúvida sobre um remédio'));
        $this->assertNull(SupportChannels::detect('posso tomar com leite?'));
    }

    public function test_each_need_points_to_its_channels(): void
    {
        $tels = fn (string $need): array => array_merge(...array_map(fn (array $group): array => array_column($group['lines'], 'tel'), SupportChannels::groups($need)));

        $this->assertSame(['188', '136'], $tels(SupportChannels::EMOTIONAL));
        $this->assertSame(['192', '136'], $tels(SupportChannels::SYMPTOM));
        $this->assertSame(['190', '192', '180', '100'], $tels(SupportChannels::VIOLENCE));
        $this->assertSame(['136', '192', '08007226001'], $tels(SupportChannels::SUBSTANCES));
        $this->assertSame([], SupportChannels::groups(SupportChannels::VAGUE), 'o pedido vago pergunta antes de indicar canais');
    }

    public function test_the_crisis_reply_only_refuses_doses_when_doses_were_asked(): void
    {
        $this->assertSame(GuardVerdict::CRISIS_REFUSAL, GuardVerdict::crisisOpening('qual a dose letal?'));
        $this->assertSame(GuardVerdict::CRISIS_REFUSAL, GuardVerdict::crisisOpening('quantos comprimidos para não acordar'));
        $this->assertSame(GuardVerdict::CRISIS_WELCOME, GuardVerdict::crisisOpening('Estou passando por um momento difícil'));
    }
}
