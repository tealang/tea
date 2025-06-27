<?php
namespace tests\syntax;

interface Colorful
{
	public function color(): string;
}

trait Rectangle
{
	public function shape(): string {
		return "Rectangle";
	}
}

enum Suit: int implements Colorful
{
	use Rectangle;

	case Hearts = 1;
	case Diamonds = 2;
	case Clubs = 3;
	case Spades = '122';

	public const A = self::Hearts;

	public function color(): string
	{
		return match($this) {
			Suit::Hearts, Suit::Diamonds => 'Red',
			Suit::Clubs => 'Black',
		};
	}

	public function shape(): string
	{
		return "Rectangle";
	}
}

function paint(Colorful $c)
{
   return $c->color();
}

paint(Suit::Clubs);

print Suit::Diamonds->shape();

var_dump(Suit::cases());
