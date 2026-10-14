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
    distance: number;
    flavor: string;
    kind: 'speed' | 'repair' | 'support' | 'routine' | 'weather';
};

export type Phase =
    | 'boarding'
    | 'dawn'
    | 'day'
    | 'reveal'
    | 'night'
    | 'vote'
    | 'inquiry'
    | 'finished';

export type PickState = 'none' | 'locked' | 'pending' | 'repick' | 'final';

export type Department = {
    key: DeptKey;
    name: string;
    code: string;
    score: number;
    atDawn: number;
    crisis: boolean;
};

export type GamePlayer = {
    id: string;
    name: string;
    department: DeptKey;
    ready: boolean;
    status: 'idle' | 'thinking' | 'picked' | 'escort' | 'vetoed';
    restricted: number;
    escortOf: string | null;
    escortedBy: string | null;
    cooldown: number;
    brakesLeft: number;
    overridesLeft: number;
    absent: boolean;
    nominable: boolean;
    canPull: boolean;
    voted: boolean;
    guessed: boolean;
    isMe: boolean;
    traitor: boolean | null;
};

export type LogEntry = {
    day: number;
    kind:
        | 'system'
        | 'day'
        | 'weather'
        | 'pick'
        | 'override'
        | 'veto'
        | 'result'
        | 'night'
        | 'brake'
        | 'vote';
    text: string;
    who?: string;
    card?: string;
    fx?: Partial<Record<DeptKey | 'distance', number>>;
    votes?: Record<string, boolean>;
    passed?: boolean;
    override?: boolean;
    auto?: boolean;
};

export type Escorting = {
    id: string;
    name: string;
    department: DeptKey;
    hand: CardData[];
    pick: string | null;
    pickState: PickState;
    decision: 'allow' | 'veto' | null;
    vetoed: string | null;
    overridesLeft: number;
    dayOfRestriction: number;
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
    vetoed: string | null;
    autoPicked: boolean;
    overridesLeft: number;
    brakesLeft: number;
    canPull: boolean;
    ready: boolean;
    vote: boolean | null;
    guess: string | null;
    restricted: number;
    escortedBy: string | null;
    escorting: Escorting | null;
    forecast: CardData | null;
    forecastOn: boolean;
};

export type DaySummary = {
    day: number;
    base: number;
    weather: CardData[];
    weatherDistance: number;
    cardDistance: number;
    dayDistance: number;
    scoresBefore: Record<string, number>;
    scoresAfterWeather: Record<string, number>;
    scoresAfter: Record<string, number>;
    distanceBefore: number;
    distanceAfter: number;
    picks: {
        player: string;
        card: CardData | null;
        override: boolean;
        auto: boolean;
        vetoed: boolean;
    }[];
};

export type BrakeResult = {
    day: number;
    puller: string;
    nominee: string;
    votes: Record<string, boolean>;
    yes: number;
    no: number;
    passed: boolean;
};

export type Reveal = {
    traitors: string[];
    guesses: { id: string; suspect: string | null; correct: boolean }[];
    days: {
        day: number;
        weather: CardData[];
        dayDistance: number;
        scoresAfter: Record<string, number>;
        distanceAfter: number;
        picks: {
            player: string;
            hand: CardData[];
            pick: string | null;
            vetoed: string | null;
            override: boolean;
            auto: boolean;
        }[];
        brake: {
            puller: string;
            nominee: string;
            passed: boolean;
            yes: number;
            no: number;
        } | null;
    }[];
    brakes: number;
    everRestricted: string[];
};

export type GameView = {
    phase: Phase;
    paused: boolean;
    deadline: number | null;
    remaining: number | null;
    day: number;
    days: number;
    route: number;
    distance: number;
    baseToday: number;
    slowDays: number;
    weatherPerDay: number;
    crisisAt: number;
    handSize: number;
    reducedHand: number;
    restrictDays: number;
    traitorCount: number;
    departments: Department[];
    weather: CardData[];
    weatherDistance: number;
    players: GamePlayer[];
    brake: {
        open: boolean;
        used: boolean;
        trainLeft: number;
        perGame: number;
        fromNight: number;
    };
    vote: { puller: string; nominee: string } | null;
    lastBrake: BrakeResult | null;
    today: DaySummary | null;
    log: LogEntry[];
    winner: 'crew' | 'traitor' | null;
    cause: 'arrived' | 'crisis' | 'clock' | 'ended' | null;
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

export type LobbyView = {
    code: string;
    version: number;
    hostId: string | null;
    hostName: string;
    isHost: boolean;
    seats: Seat[];
    platform: { id: string; name: string; isMe: boolean }[];
    preset: 'easy' | 'standard' | 'hard';
    setup: {
        players: number;
        route: number;
        days: number;
        weather: number;
        traitors: number;
        forecast: boolean;
        brakeFrom: number;
        brakesPerPlayer: number;
        brakesPerGame: number;
        restrictDays: number;
        startScore: number;
        crisisAt: number;
        daySeconds: number;
        nightSeconds: number;
    };
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
