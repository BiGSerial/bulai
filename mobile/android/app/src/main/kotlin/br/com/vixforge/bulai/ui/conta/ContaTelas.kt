package br.com.vixforge.bulai.ui.conta

import androidx.compose.animation.AnimatedContent
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.MarkEmailRead
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material3.Checkbox
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import br.com.vixforge.bulai.R
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.BotaoSecundario
import br.com.vixforge.bulai.ui.componentes.BotaoTexto
import br.com.vixforge.bulai.ui.componentes.Campo
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.EstadoVazio
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro

/**
 * Moldura das telas de conta: logo, título, texto de apoio e o formulário num cartão. Rola só quando o teclado ou a letra
 * grande não deixam caber; centraliza em telas largas (tablet, celular deitado).
 */
@Composable
private fun MolduraDeConta(
    titulo: String,
    apoio: String?,
    aoVoltar: (() -> Unit)? = null,
    rodape: (@Composable ColumnScope.() -> Unit)? = null,
    conteudo: @Composable ColumnScope.() -> Unit,
) {
    Surface(color = MaterialTheme.colorScheme.background, modifier = Modifier.fillMaxSize()) {
        BoxWithConstraints(Modifier.fillMaxSize().safeDrawingPadding().imePadding()) {
            val alturaDaTela = maxHeight
            Column(
                Modifier
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState())
                    .heightIn(min = alturaDaTela)
                    .padding(horizontal = 20.dp, vertical = 12.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.SpaceBetween,
            ) {
                Column(Modifier.widthIn(max = 520.dp).fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
                        if (aoVoltar != null) {
                            IconButton(onClick = aoVoltar) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Voltar") }
                        }
                    }
                    Image(painterResource(R.drawable.marca_logo), contentDescription = "BULA-IA — Bulas Inteligentes", modifier = Modifier.height(56.dp))
                    Spacer(Modifier.height(16.dp))
                    Text(titulo, style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.primary, textAlign = TextAlign.Center, modifier = Modifier.semantics { heading() })
                    apoio?.let {
                        Spacer(Modifier.height(6.dp))
                        Text(it, style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center)
                    }
                    Spacer(Modifier.height(20.dp))
                    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(14.dp), content = conteudo)
                }
                Column(Modifier.widthIn(max = 520.dp).fillMaxWidth().padding(top = 16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    rodape?.invoke(this)
                    Text(
                        "O BULA-IA não substitui seu médico ou farmacêutico.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.padding(top = 12.dp),
                    )
                }
            }
        }
    }
}

@Composable
fun EntrarTela(aoCadastrar: () -> Unit, aoEsquecerSenha: () -> Unit) {
    val vm = telaViewModel { EntrarViewModel(it.sessao, it.api) }
    val foco = LocalFocusManager.current
    MolduraDeConta(
        titulo = "Entrar",
        apoio = "Acesse com o e-mail e a senha da sua conta.",
        rodape = {
            HorizontalDivider(Modifier.padding(vertical = 8.dp))
            Text("Ainda não tem conta?", style = MaterialTheme.typography.titleSmall, color = MaterialTheme.colorScheme.primary)
            Spacer(Modifier.height(8.dp))
            BotaoSecundario("Criar minha conta", aoCadastrar)
        },
    ) {
        Cartao {
            FaixaDeErro(vm.erro)
            Campo(
                vm.email, { vm.email = it; vm.limparErro("email") }, "E-mail",
                erro = vm.errosDosCampos["email"], tipo = KeyboardType.Email,
                autoPreenchimento = listOf(ContentType.EmailAddress, ContentType.Username),
            )
            Campo(
                vm.senha, { vm.senha = it; vm.limparErro("password") }, "Senha",
                erro = vm.errosDosCampos["password"], senha = true, acao = ImeAction.Go,
                aoConcluir = { foco.clearFocus(); vm.entrar() },
                autoPreenchimento = listOf(ContentType.Password),
            )
            BotaoPrincipal("Entrar", { foco.clearFocus(); vm.entrar() }, carregando = vm.enviando, destaque = true)
            BotaoTexto("Esqueci minha senha", aoEsquecerSenha, Modifier.align(Alignment.CenterHorizontally))
        }
    }
}

@Composable
fun CadastroTela(aoVoltar: () -> Unit) {
    val vm = telaViewModel { CadastroViewModel(it.conta) }
    val foco = LocalFocusManager.current
    AnimatedContent(vm.concluido, label = "cadastro") { concluido ->
        if (concluido != null) {
            MolduraDeConta("Cadastro enviado", null, aoVoltar) {
                EstadoVazio(Icons.Rounded.TaskAlt, "Tudo certo", concluido) {
                    BotaoPrincipal("Voltar para entrar", aoVoltar)
                }
            }
        } else {
            MolduraDeConta("Criar minha conta", "A equipe aprova cada pedido e avisa você por e-mail.", aoVoltar) {
                Cartao {
                    FaixaDeErro(vm.erro)
                    Campo(vm.nome, { vm.nome = it; vm.limparErro("name") }, "Nome completo", erro = vm.errosDosCampos["name"], autoPreenchimento = listOf(ContentType.PersonFullName))
                    Campo(vm.email, { vm.email = it; vm.limparErro("email") }, "E-mail", erro = vm.errosDosCampos["email"], tipo = KeyboardType.Email, autoPreenchimento = listOf(ContentType.EmailAddress))
                    Campo(
                        vm.senha, { vm.senha = it; vm.limparErro("password") }, "Senha", erro = vm.errosDosCampos["password"], senha = true,
                        ajuda = "Pelo menos 12 caracteres, com letras e números.", autoPreenchimento = listOf(ContentType.NewPassword),
                    )
                    Campo(vm.confirmacao, { vm.confirmacao = it; vm.limparErro("password") }, "Repita a senha", senha = true, acao = ImeAction.Done, aoConcluir = { foco.clearFocus() }, autoPreenchimento = listOf(ContentType.NewPassword))
                    AvisoDoProjeto()
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .heightIn(min = 56.dp)
                            .toggleable(vm.aceitou, role = Role.Checkbox) { vm.aceitou = it; vm.limparErro("terms") },
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Checkbox(checked = vm.aceitou, onCheckedChange = null)
                        Spacer(Modifier.padding(start = 8.dp))
                        Text("Li e concordo com este aviso. Entendo que o BULA-IA é um projeto acadêmico, que não substitui médico ou farmacêutico e que o meu acesso depende de aprovação.", style = MaterialTheme.typography.bodyMedium)
                    }
                    vm.errosDosCampos["terms"]?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
                    BotaoPrincipal("Enviar cadastro", { foco.clearFocus(); vm.cadastrar() }, carregando = vm.enviando, destaque = true)
                }
            }
        }
    }
}

/** O mesmo aviso da tela de cadastro do site (resources/views/auth/register.blade.php). */
@Composable
private fun AvisoDoProjeto() {
    Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = MaterialTheme.shapes.medium) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text("Projeto acadêmico: acesso limitado", style = MaterialTheme.typography.titleSmall, color = MaterialTheme.colorScheme.onPrimaryContainer)
            Text(
                "O BULA-IA é um protótipo criado como Trabalho de Conclusão de Curso (TCC). Por isso, o número de acessos é " +
                    "limitado e cada cadastro passa pela aprovação da equipe do projeto antes do primeiro uso. Você recebe um " +
                    "e-mail assim que o seu acesso for liberado.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onPrimaryContainer,
            )
        }
    }
}

@Composable
fun EsqueciSenhaTela(aoVoltar: () -> Unit) {
    val vm = telaViewModel { EsqueciSenhaViewModel(it.conta) }
    val foco = LocalFocusManager.current
    MolduraDeConta("Esqueci minha senha", "Enviamos um link para você criar uma senha nova.", aoVoltar) {
        AnimatedContent(vm.enviado, label = "esqueci") { enviado ->
            if (enviado != null) {
                EstadoVazio(Icons.Rounded.MarkEmailRead, "Confira o seu e-mail", "$enviado Abra o link no celular ou no computador.") {
                    BotaoPrincipal("Voltar para entrar", aoVoltar)
                }
            } else {
                Cartao {
                    FaixaDeErro(vm.erro)
                    Campo(
                        vm.email, { vm.email = it; vm.limparErro("email") }, "E-mail", erro = vm.errosDosCampos["email"], tipo = KeyboardType.Email,
                        acao = ImeAction.Send, aoConcluir = { foco.clearFocus(); vm.pedir() }, autoPreenchimento = listOf(ContentType.EmailAddress),
                    )
                    BotaoPrincipal("Enviar o link", { foco.clearFocus(); vm.pedir() }, carregando = vm.enviando, destaque = true)
                }
            }
        }
    }
}

@Composable
fun PrimeiroAcessoTela(primeiroNome: String) {
    val vm = telaViewModel { PrimeiroAcessoViewModel(it.sessao, it.api) }
    val foco = LocalFocusManager.current
    MolduraDeConta(
        titulo = "Olá, $primeiroNome",
        apoio = "Você entrou com uma senha provisória. Crie a sua senha para continuar.",
        rodape = { BotaoTexto("Sair e entrar com outra conta", vm::sair) },
    ) {
        Cartao {
            FaixaDeErro(vm.erro)
            Campo(
                vm.senha, { vm.senha = it; vm.limparErro("password") }, "Nova senha", erro = vm.errosDosCampos["password"], senha = true,
                ajuda = "Pelo menos 12 caracteres, com letras e números.", autoPreenchimento = listOf(ContentType.NewPassword),
            )
            Campo(vm.confirmacao, { vm.confirmacao = it; vm.limparErro("password") }, "Repita a nova senha", senha = true, acao = ImeAction.Done, aoConcluir = { foco.clearFocus(); vm.salvar() }, autoPreenchimento = listOf(ContentType.NewPassword))
            BotaoPrincipal("Salvar e continuar", { foco.clearFocus(); vm.salvar() }, carregando = vm.enviando, destaque = true)
        }
    }
}
