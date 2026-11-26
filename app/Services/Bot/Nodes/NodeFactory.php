<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\Flow\NodeType;

/**
 * O mapa do tipo do bloco para a classe dele.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") um mapa do tipo do bloco para a classe dele: o acoplamento é a função da classe
 */
final class NodeFactory
{
    public static function make(NodeType $type): Node
    {
        return match ($type) {
            NodeType::Inicio => new InicioNode,
            NodeType::Mensagem => new MensagemNode,
            NodeType::MenuSetores => new MenuSetoresNode,
            NodeType::Menu => new MenuNode,
            NodeType::Transferir => new TransferirNode,
            NodeType::Encerrar => new EncerrarNode,
            NodeType::Pergunta => new PerguntaNode,
            NodeType::Condicao => new CondicaoNode,
            NodeType::Esperar => new EsperarNode,
            NodeType::Midia => new MidiaNode,
            NodeType::SalvarContato => new SalvarContatoNode,
            NodeType::Etiquetar => new EtiquetarNode,
            NodeType::Nota => new NotaNode,
        };
    }
}
