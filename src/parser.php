<?php

namespace igorw\edn;

use Ardent\LinkedList;
use Ardent\Vector;
use Ardent\HashMap;
use Ardent\HashSet;
use Phlexy\LexerFactory\Stateless\UsingPregReplace;
use Phlexy\LexerDataGenerator;

function parse($edn, array $tagHandlers = []) {
    $tokens = tokenize($edn);
    $ast = parse_tokens($tokens, $edn);
    $ast = apply_tag_handlers($ast, $tagHandlers);

    return $ast;
}

function tokenize($edn) {
    $factory = new UsingPregReplace(new LexerDataGenerator());

    $lexer = $factory->createLexer([
        ';(?:.*)(?:\\n)?'                => 'comment',
        '#_\s?\S+'                       => 'discard',
        'nil|true|false'                 => 'literal',
        '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"' => 'string',
        '[\s,]'                          => 'whitespace',
        '\\\\[a-z]+'                     => 'character',
        '(?:[+-]?)(?:[0-9]+\.[0-9]+)M?'  => 'float',
        '(?:[+-]?)(?:[0-9]+)N?'          => 'int',
        get_symbol_regex()               => 'symbol',
        ':(?:'.get_symbol_regex().')'    => 'keyword',
        '#(?:'.get_symbol_regex().')'    => 'tag',
        '\\('                            => 'list_start',
        '\\)'                            => 'list_end',
        '\\['                            => 'vector_start',
        '\\]'                            => 'vector_end',
        '#\\{'                           => 'set_start',
        '\\{'                            => 'map_start',
        '\\}'                            => 'map_set_end',
    ]);

    $tokens = $lexer->lex($edn);

    return $tokens;
}

function parse_tokens(array $tokens, $edn) {
    $dataFactories = [
        'list_start'    => __NAMESPACE__.'\\create_list',
        'vector_start'  => __NAMESPACE__.'\\create_vector',
        'map_start'     => __NAMESPACE__.'\\create_map',
        'set_start'     => __NAMESPACE__.'\\create_set',
    ];

    $ast = [];

    $tokens = array_values(array_filter($tokens, function ($token) {
        return !in_array($token[0], ['whitespace', 'comment', 'discard']);
    }));

    $i = 0;
    $size = count($tokens);

    while ($i < $size) {
        $type = $tokens[$i][0];
        if (isset($dataFactories[$type])) {
            $result = parse_subtree($dataFactories[$type], $tokens, $i, $edn);
            $ast[] = $result['subtree'];
            $i = $result['i'];

            continue;
        }

        $ast[] = parse_token($tokens[$i++]);
    }

    $ast = wrap_tags($ast);

    return $ast;
}

function parse_subtree($dataFactory, array $tokens, $i, $edn) {
    $startTypes = ['list_start', 'vector_start', 'map_start', 'set_start'];
    $endTypes = ['list_end', 'vector_end', 'map_set_end'];

    $subtree = null;
    $level = 0;
    $j = 0;

    foreach (array_slice($tokens, $i) as $j => $token) {
        if (in_array($token[0], $startTypes, true)) {
            $level++;
        }

        if (in_array($token[0], $endTypes, true)) {
            $level--;
        }

        if (0 === $level) {
            $subtree = $dataFactory(parse_tokens(array_slice($tokens, $i+1, $j-1), $edn));
            break;
        }
    }

    return [
        'subtree'   => $subtree,
        'i'         => $i+$j+1,
    ];
}

function parse_token(array $token) {
    list($type, $_, $edn) = $token;

    switch ($type) {
        case 'literal':
            return resolve_literal($edn);
        case 'string':
            return resolve_string(substr($edn, 1, -1));
        case 'character':
            return resolve_character(substr($edn, 1));
        case 'symbol':
            return Symbol::get($edn);
        case 'keyword':
            return Keyword::get(substr($edn, 1));
        case 'tag':
            return new Tag(substr($edn, 1));
        case 'int':
            return resolve_int($edn);
        case 'float':
            return resolve_float($edn);
    }

    throw new ParserException(sprintf('Could not parse input %s.', $edn));
}

function resolve_literal($edn) {
    switch ($edn) {
        case 'nil':
            return null;
        case 'true':
            return true;
        case 'false':
            return false;
    }

    throw new ParserException(sprintf('Could not parse input %s as litral.', $edn));
}

function resolve_string($edn) {
    return strtr($edn, [
        '\t' => "\t",
        '\r' => "\r",
        '\n' => "\n",
        '\"' => "\"",
    ]);
}

function resolve_character($edn) {
    $chars = [
        'newline'   => "\n",
        'return'    => "\r",
        'space'     => ' ',
        'tab'       => "\t",
    ];

    return isset($chars[$edn]) ? $chars[$edn] : $edn;
}

function get_symbol_regex() {
    $alpha = 'a-zA-Z';
    $alphaNum = 'a-zA-Z0-9';
    $chars = '*!_?$%&=.+-';
    $extraChars = '.+-';

    $nonNumericRegex = "[$extraChars][$alpha$chars][$alphaNum$chars]*|[$alpha$chars][$alphaNum$chars]*";

    $symbolRegex = [];
    $symbolRegex[] = "/{0}[$alpha$chars][$alphaNum$chars]*/(?:$nonNumericRegex)/{0}";
    $symbolRegex[] = $nonNumericRegex;
    $symbolRegex[] = '/';

    return implode('|', $symbolRegex);
}

function resolve_int($edn) {
    if (preg_match('#^([+-]?)([0-9]+)N?$#', $edn, $matches)) {
        $factor = ($matches[1] && '-' === $matches[1]) ? -1 : 1;
        return (int) $matches[2] * $factor;
    }

    throw new ParserException(sprintf('Could not parse input %s as int.', $edn));
}

function resolve_float($edn) {
    if (preg_match('#^([+-]?)([0-9]+\.[0-9]+)M?$#', $edn, $matches)) {
        $factor = ($matches[1] && '-' === $matches[1]) ? -1 : 1;
        return (float) $matches[2] * $factor;
    }

    throw new ParserException(sprintf('Could not parse input %s as float.', $edn));
}

function wrap_tags(array $ast) {
    $tag = null;

    foreach ($ast as $i => $node) {
        if ($node instanceof Tag) {
            $tag = $node;
            unset($ast[$i]);

            continue;
        }

        if ($tag) {
            $ast[$i] = new Tagged($tag, $node);
            $tag = null;
        }
    }

    return array_values($ast);
}

function create_list(array $data) {
    $list = new LinkedList();
    foreach ($data as $item) {
        $list->push($item);
    }
    return $list;
}

function create_vector(array $data) {
    $r = new \ReflectionClass('Ardent\Vector');
    return $r->newInstanceArgs($data);
}

function create_map(array $data) {
    $map = new HashMap('serialize');

    $prev = null;
    foreach ($data as $value) {
        if (!$prev) {
            $prev = $value;
            continue;
        }

        $map->insert($prev, $value);
        $prev = null;
    }

    return $map;
}

function create_set(array $data) {
    $set = new HashSet('serialize');
    foreach ($data as $item) {
        $set->add($item);
    }
    return $set;
}

function apply_tag_handlers(array $ast, array $tagHandlers) {
    if (!$tagHandlers) {
        return $ast;
    }

    foreach ($ast as $i => $node) {
        if ($node instanceof Tagged && isset($tagHandlers[$node->tag->name])) {
            $handler = $tagHandlers[$node->tag->name];
            $ast[$i] = $handler($node->value);
            continue;
        }

        if ($node instanceof Collection) {
            $ast[$i] = apply_tag_handlers(iterator_to_array($node), $tagHandlers);
            continue;
        }
    }

    return $ast;
}
