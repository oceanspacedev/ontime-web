import { Ionicons } from '@expo/vector-icons';
import { View } from 'react-native';

import { Badge, Row } from '@/components/ui';
import type { ClientDivision } from '@/lib/api';
import { useTheme } from '@/theme';

export interface DivisionNode {
  division: ClientDivision;
  depth: number;
}

/**
 * Orders divisions depth first so each child follows its parent, with its depth for indenting.
 * A division whose parent is missing from the list (or sits in a loop) is shown at the top level.
 */
export function flattenDivisions(divisions: ClientDivision[]): DivisionNode[] {
  const ids = new Set(divisions.map((division) => division.id));
  const children = new Map<number | null, ClientDivision[]>();
  for (const division of divisions) {
    const parent = division.parent_id !== null && ids.has(division.parent_id) ? division.parent_id : null;
    children.set(parent, [...(children.get(parent) ?? []), division]);
  }
  for (const list of children.values()) {
    list.sort((a, b) => a.name.localeCompare(b.name, 'id'));
  }

  const result: DivisionNode[] = [];
  const visited = new Set<number>();
  const walk = (parent: number | null, depth: number) => {
    for (const division of children.get(parent) ?? []) {
      if (visited.has(division.id)) {
        continue;
      }
      visited.add(division.id);
      result.push({ division, depth });
      walk(division.id, depth + 1);
    }
  };
  walk(null, 0);
  for (const division of divisions) {
    if (!visited.has(division.id)) {
      visited.add(division.id);
      result.push({ division, depth: 0 });
      walk(division.id, 1);
    }
  }
  return result;
}

/** The division itself and everything under it, which can never become its parent. */
export function divisionAndDescendantIds(divisions: ClientDivision[], divisionId: number): Set<number> {
  const blocked = new Set<number>([divisionId]);
  let grew = true;
  while (grew) {
    grew = false;
    for (const division of divisions) {
      if (division.parent_id !== null && blocked.has(division.parent_id) && !blocked.has(division.id)) {
        blocked.add(division.id);
        grew = true;
      }
    }
  }
  return blocked;
}

/** One division in the tree, pushed right by its depth. */
export function DivisionTreeRow({ node, onPress }: { node: DivisionNode; onPress?: () => void }) {
  const { colors } = useTheme();
  const { division, depth } = node;
  const indent = Math.min(depth, 4) * 18;
  return (
    <Row
      left={
        <View style={{ flexDirection: 'row', alignItems: 'center', paddingLeft: indent }}>
          <Ionicons name={depth === 0 ? 'git-network-outline' : 'return-down-forward-outline'} size={depth === 0 ? 24 : 20} color={colors.textMuted} />
        </View>
      }
      title={division.name}
      subtitle={division.code ?? undefined}
      right={<Badge label={division.is_active ? 'Aktif' : 'Nonaktif'} tone={division.is_active ? 'success' : 'gray'} />}
      onPress={onPress}
    />
  );
}
