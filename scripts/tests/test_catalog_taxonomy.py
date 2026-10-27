import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from catalog_taxonomy import classify, enrich

class CatalogGroups(unittest.TestCase):
    def test_groups_ignore_description_contents(self):
        cases = {
            'Флорариум «Лес»': 'florarium',
            'Грунт для флорариума': 'material',
            'Коряга для террариума': 'material',
            'Набор материалов для флорариума': 'material',
            'Пустой флорариум': 'material',
            'Пустынный флорариум Дюна': 'florarium',
            'Мини-садик «Колыбель пустыни» в кашпо': 'composition',
            'Кашпо-котик с розовым криптантусом': 'potted',
            'Кашпо-панда с живым суккулентом': 'potted',
            'Папоротник в кашпо с автополивом': 'potted',
            'Маранта в тех.горшке': 'plant',
            'Черенок бегонии в горшке': 'plant',
            'Живой мох': 'plant',
            'Мох стабилизированный': 'material',
            'Селагинелла на коряге': 'plant',
            'Мини-сад с декоративными камнями': 'composition',
            'Флорариум с декоративными фигурками': 'florarium',
            'Кашпо керамическое': 'material',
        }
        for title, expected in cases.items():
            with self.subTest(title=title):
                self.assertEqual(classify({'title':title, 'description':'Флорариум, грунт, доставка, горшок'}),expected)
    def test_manual_decision_wins_and_stays_stable(self):
        p = {'id':'avito-123', 'title':'Мох стабилизированный', 'category':'plant'}
        self.assertEqual(enrich(p, {'avito-123':'material'})['catalogGroup'],'material')
        p['title'] += ' новый заголовок'
        self.assertEqual(enrich(p, {'avito-123':'material'})['catalogGroup'],'material')
        self.assertNotIn('catalogGroup', {'id':'avito-123'})
    def test_tags_do_not_change_group(self):
        p=enrich({'id':'1','title':'Фикус в белом кашпо'})
        self.assertEqual(p['catalogGroup'],'potted')
        self.assertEqual(p['tags'],['ficus'])

if __name__=='__main__': unittest.main()
