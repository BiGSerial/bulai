import Foundation

/// Uma notificação local planejada para uma dose (ou a repetição dela, no modo "insistir").
public struct LembretePlanejado: Equatable, Sendable {
    public var dose: DoseDaAgenda
    public var quando: Date
    /// 0: o lembrete da hora; 1, 2…: repetições enquanto a dose não for marcada.
    public var repeticao: Int

    public var chave: String { dose.key }

    public var identificador: String {
        LembretePlanejado.identificador(chave: dose.key, repeticao: repeticao)
    }

    public static let prefixo = "dose|"

    public static func identificador(chave: String, repeticao: Int) -> String {
        repeticao == 0 ? prefixo + chave : prefixo + chave + "#\(repeticao)"
    }

    /// Todos os identificadores possíveis de uma dose (para cancelar a dose e as repetições juntas).
    public static func identificadores(chave: String) -> [String] {
        (0 ... PlanejadorDeLembretes.repeticoes).map { identificador(chave: chave, repeticao: $0) }
    }

    /// Chave da dose a partir do identificador da notificação.
    public static func chave(doIdentificador identificador: String) -> String? {
        guard identificador.hasPrefix(prefixo) else {
            return nil
        }
        let resto = identificador.dropFirst(prefixo.count)
        if let marca = resto.lastIndex(of: "#"), Int(resto[resto.index(after: marca)...]) != nil {
            return String(resto[..<marca])
        }
        return String(resto)
    }
}

/// Regras de quando cada dose vira notificação local (a versão iOS do `AgendadorDeAlarmes` do Android).
///
/// - lembra cada dose com `remind = true` ainda não marcada (nem no servidor, nem por este aparelho);
/// - dose adiada lembra no novo horário; adiamento que já passou foi entregue pelo sistema e não volta;
/// - lembrete que já foi entregue (horário planejado no passado) não toca de novo numa nova sincronização;
/// - dose cujo horário passou há até 15 min e nunca foi planejada (agenda chegou atrasada) lembra logo;
/// - no modo "insistir", repete o lembrete enquanto a dose não for marcada;
/// - o iOS guarda no máximo 64 notificações pendentes por app: ficam as ``limite`` mais próximas.
public enum PlanejadorDeLembretes {
    public static let janelaDeAtraso: TimeInterval = 15 * 60
    public static let repeticoes = 2
    /// Abaixo das 64 do sistema, deixando espaço para avisos e para o lembrete de teste.
    public static let limite = 56

    public static func planejar(
        agenda: AgendaDoAparelho?,
        agora: Date,
        marcadas: Set<String>,
        adiados: [String: Date],
        jaPlanejados: [String: Date],
        insistir: Bool,
        intervaloDeInsistenciaMinutos: Int
    ) -> [LembretePlanejado] {
        guard let agenda else {
            return []
        }
        var planejados: [LembretePlanejado] = []
        for dose in agenda.dosesEmOrdem {
            guard dose.remind, !dose.marcada, !marcadas.contains(dose.key), let momento = dose.momento else {
                continue
            }
            let quando: Date
            if let adiadoPara = adiados[dose.key] {
                guard adiadoPara > agora else {
                    continue
                }
                quando = adiadoPara
            } else if let anterior = jaPlanejados[dose.key], anterior <= agora {
                // Já tocou: no modo "insistir", só as repetições que ainda faltam continuam.
                if insistir {
                    planejados += repetir(dose, depoisDe: anterior, minutos: intervaloDeInsistenciaMinutos).filter { $0.quando > agora }
                }
                continue
            } else if momento > agora {
                quando = momento
            } else if momento > agora.addingTimeInterval(-janelaDeAtraso) {
                quando = agora.addingTimeInterval(2)
            } else {
                continue
            }
            planejados.append(LembretePlanejado(dose: dose, quando: quando, repeticao: 0))
            if insistir {
                planejados += repetir(dose, depoisDe: quando, minutos: intervaloDeInsistenciaMinutos)
            }
        }
        return Array(planejados.sorted { ($0.quando, $0.repeticao) < ($1.quando, $1.repeticao) }.prefix(limite))
    }

    private static func repetir(_ dose: DoseDaAgenda, depoisDe inicio: Date, minutos: Int) -> [LembretePlanejado] {
        (1 ... repeticoes).map { repeticao in
            LembretePlanejado(dose: dose, quando: inicio.addingTimeInterval(TimeInterval(repeticao * max(minutos, 1) * 60)), repeticao: repeticao)
        }
    }

    /// Lembretes "já planejados" para guardar depois de agendar: os novos (só a hora, sem repetições) e os que já
    /// passaram e continuam na agenda (para não tocarem de novo).
    public static func registrar(_ planejados: [LembretePlanejado], anteriores: [String: Date], agenda: AgendaDoAparelho?, agora: Date) -> [String: Date] {
        let chaves = Set(agenda?.doses.map(\.key) ?? [])
        var registro = anteriores.filter { chaves.contains($0.key) && $0.value <= agora }
        for lembrete in planejados where lembrete.repeticao == 0 {
            registro[lembrete.chave] = lembrete.quando
        }
        return registro
    }

    /// Próxima dose ainda não marcada (widget e "Próximo lembrete" da Conta): até ``AgendaDoAparelho/atrasoMinutos``
    /// depois do horário ela ainda aparece, como no widget do Android.
    public static func proximaDose(agenda: AgendaDoAparelho?, agora: Date, marcadas: Set<String>) -> DoseDaAgenda? {
        guard let agenda else {
            return nil
        }
        let limite = agora.addingTimeInterval(-TimeInterval(agenda.atrasoMinutos * 60))
        return agenda.dosesEmOrdem.first { dose in
            guard let momento = dose.momento else {
                return false
            }
            return !dose.marcada && !marcadas.contains(dose.key) && momento > limite
        }
    }
}
