export type DeptKey =
    | 'engineering'
    | 'operations'
    | 'passenger_services'
    | 'security';

export type CardData = {
    id: string;
    name: string;
    department: DeptKey | null;
    effects: Partial<Record<DeptKey, number>>;
    flavor: string;
    kind: 'build' | 'push' | 'support' | 'routine' | 'weather';
    special: string;
};

export type Phase =
    | 'boarding'
    | 'dawn'
    | 'day'
    | 'reveal'
    | 'dusk'
    | 'departure'
    | 'night'
    | 'vote'
    | 'arrival'
    | 'finished';

export type PickState = 'none' | 'locked' | 'pending' | 'repick' | 'final';

export type Cause =
    | 'arrived'
    | 'named'
    | 'never_pulled'
    | 'wrong_name'
    | 'tie_vote'
    | 'crisis'
    | 'clock'
    | 'ended';

export type Department = {
    key: DeptKey;
    name: string;
    code: string;
    score: number;
    atDawn: number;
    crisis: boolean;
    atLine: boolean;
};

export type GamePlayer = {
    id: string;
    name: string;
    department: DeptKey;
    ready: boolean;
    status: 'idle' | 'thinking' | 'picked' | 'escort' | 'vetoed';
    detained: number;
    escortOf: string | null;
    escortedBy: string | null;
    cooldown: number;
    overridesLeft: number;
    absent: boolean;
    nominable: boolean;
    canNominate: boolean;
    voted: boolean;
    accused: boolean;
    everDetained: boolean;
    isMe: boolean;
    traitor: boolean | null;
};

export type LogEntry = {
    day: number;
    kind:
        | 'system'
        | 'day'
        | 'weather'
        | 'tailwind'
        | 'pick'
        | 'override'
        | 'veto'
        | 'signal'
        | 'run'
        | 'cord'
        | 'night'
        | 'nominate'
        | 'vote'
        | 'arrival'
        | 'result';
    text: string;
    who?: string;
    card?: string;
    fx?: Partial<Record<DeptKey, number>>;
    votes?: Record<string, boolean>;
    passed?: boolean;
    override?: boolean;
    auto?: boolean;
    clue?: string[];
};

export type Escorting = {
    id: string;
    name: string;
    department: DeptKey;
    hand: CardData[];
    pick: string | null;
    pickState: PickState;
    decision: 'allow' | 'veto' | null;
    vetoed: string[];
    vetoesLeft: number;
    overridesLeft: number;
    dayOfDetention: number;
};

export type Me = {
    id: string;
    department: DeptKey;
    traitor: boolean;
    partners: string[];
    teammates: string[];
    hand: CardData[];
    pick: string | null;
    pickState: PickState;
    vetoed: string[];
    autoPicked: boolean;
    overridesLeft: number;
    canPull: boolean;
    cordPulled: boolean;
    pulledByMe: boolean;
    traitorsPulled: number | null;
    myPulls: number;
    ready: boolean;
    vote: boolean | null;
    accuse: string | null;
    detained: number;
    escortedBy: string | null;
    escorting: Escorting | null;
    canNominate: boolean;
    nominatedTonight: boolean;
};

export type Dusk = {
    green: boolean;
    window: boolean;
    line: number;
    scoresBefore: Record<string, number>;
    outcome: 'ran' | 'pulled' | 'red' | null;
    clue: string[];
    free: boolean;
    arrived: boolean;
    legBefore: number;
};

export type DaySummary = {
    day: number;
    weather: CardData[];
    scoresBefore: Record<string, number>;
    scoresAfterWeather: Record<string, number>;
    scoresAfter: Record<string, number>;
    picks: {
        player: string;
        card: CardData | null;
        override: boolean;
        auto: boolean;
        vetoed: boolean;
    }[];
};

export type VoteResult = {
    nominator: string;
    nominee: string;
    votes: Record<string, boolean>;
    yes: number;
    no: number;
    passed: boolean;
};

export type Reveal = {
    traitors: string[];
    pulls: { day: number; by: string; clue: string[] }[];
    accusations: { id: string; suspect: string | null }[];
    days: {
        day: number;
        weather: CardData[];
        scoresAfter: Record<string, number>;
        picks: {
            player: string;
            hand: CardData[];
            pick: string | null;
            vetoed: string[];
            override: boolean;
            auto: boolean;
            escort: string | null;
        }[];
        dusk: {
            green: boolean;
            outcome: 'ran' | 'pulled' | 'red';
            puller: string | null;
            clue: string[];
            free: boolean;
            legAfter: number;
            scoresAfter: Record<string, number>;
        } | null;
        nominations: {
            nominator: string;
            nominee: string;
            yes: number;
            no: number;
            passed: boolean;
        }[];
        arrival: {
            tally: Record<string, number>;
            top: string | null;
            tie: boolean;
        } | null;
    }[];
    everDetained: string[];
    leg: number;
};

export type Station = { name: string; line: number };

export type GameView = {
    phase: Phase;
    paused: boolean;
    deadline: number | null;
    remaining: number | null;
    day: number;
    days: number;
    legs: number;
    leg: number;
    line: number;
    stations: Station[];
    freeRun: boolean;
    cordLeft: number;
    cordPulls: number;
    clueSize: number;
    scoreMax: number;
    crisisAt: number;
    runCost: number;
    handSize: number;
    reducedHand: number;
    detainDays: number;
    weatherPerDay: number;
    traitorCount: number;
    accusation: boolean;
    mustPull: boolean;
    departments: Department[];
    weather: CardData[];
    players: GamePlayer[];
    dusk: Dusk | null;
    pulls: { day: number; clue: string[] }[];
    vote: { nominator: string; nominee: string } | null;
    nominationsOpen: boolean;
    lastVote: VoteResult | null;
    today: DaySummary | null;
    log: LogEntry[];
    winner: 'crew' | 'traitor' | null;
    cause: Cause | null;
    me: Me | null;
    reveal: Reveal | null;
};

export type Seat = {
    id: string;
    name: string;
    ready: boolean;
    isHost: boolean;
    isMe: boolean;
};

export type SettingField = {
    key: string;
    label: string;
    group: string;
    min: number;
    max: number;
    kind: 'number' | 'switch' | 'seconds';
    help: string;
};

export type Preset = 'easy' | 'standard' | 'hard' | 'custom';

export type LobbyView = {
    code: string;
    version: number;
    hostId: string | null;
    hostName: string;
    isHost: boolean;
    seats: Seat[];
    platform: { id: string; name: string; isMe: boolean }[];
    preset: Preset;
    custom: Record<string, number>;
    setup: {
        players: number;
        departments: DeptKey[];
        rules: Record<string, number>;
        standard: Record<string, number>;
        presetDays: Record<'easy' | 'standard' | 'hard', number>;
    };
    fields: SettingField[];
    readyCount: number;
    canStart: boolean;
    minPlayers: number;
    maxPlayers: number;
    wins: {
        id: string;
        name: string;
        crew: number;
        traitor: number;
        total: number;
        isMe: boolean;
    }[];
    trips: number;
    notice: string | null;
    inProgress: boolean;
};

export type BoardState = {
    serverNow: number;
    me: { id: string; name: string | null };
    membership: 'seated' | 'platform' | 'removed' | 'visitor';
    screen:
        | 'join'
        | 'full'
        | 'removed'
        | 'platform'
        | 'lobby'
        | 'game'
        | 'final';
    lobby: LobbyView;
    game: GameView | null;
};

export type StationState = {
    serverNow: number;
    lobby: LobbyView;
    game: GameView | null;
};
